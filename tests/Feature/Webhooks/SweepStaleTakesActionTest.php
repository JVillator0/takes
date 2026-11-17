<?php

declare(strict_types=1);

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Suggestions\CreateSuggestionAction;
use App\Actions\Takes\SweepStaleTakesAction;
use App\DTOs\Takes\SweepResultDTO;
use App\Enums\Auth\AdminRole;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Enums\Suggestions\SuggestionFailureReason;
use App\Enums\Suggestions\SuggestionStatus;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\ReconcileStatus;
use App\Events\TakeUpdated;
use App\Jobs\ReconcileTakeJob;
use App\Jobs\SweepStaleTakesJob;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\User;
use App\Support\Takes\ReconcileStopMarker;
use Database\Seeders\AdminRolesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Billing;
use Tests\Support\PixelforgeWebhook;
use Tests\Support\Takes;

/*
| SweepStaleTakesAction (D-036, W-16): page loads restart lost reconcile
| chains and expire stuck suggestions, at most once per 2 minutes.
*/

beforeEach(function (): void {
    config(['pixelforge.api_key' => 'pf_test_sweep']);
    // Takes moved by real snapshots start reconcile chains; keep them queued, never sent.
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();
    Event::fake([TakeUpdated::class]);
});

function sweep(bool $force = false): SweepResultDTO
{
    return app(SweepStaleTakesAction::class)->execute($force);
}

/** A take in $status created $minutes ago. */
function takeAged(string $status, int $minutes): Take
{
    $take = Takes::in($status);
    $take->forceFill(['created_at' => now()->subMinutes($minutes)])->save();

    return $take;
}

/** A pending suggestion with its AI hold open, created $minutes ago with spec 8's 2-minute expiry. */
function pendingSuggestion(int $minutes): Suggestion
{
    $customer = Billing::fundedCustomer();
    $suggestion = Suggestion::factory()->create(['customer_id' => $customer->id]);

    DB::transaction(fn (): UsageRecord => app(OpenUsageAction::class)->execute(
        $suggestion,
        Provider::Anthropic,
        Billing::estimate(5_000_000),
        Billing::anthropicRates(),
    ));
    $suggestion->forceFill([
        'created_at' => now()->subMinutes($minutes),
        'expires_at' => now()->subMinutes($minutes)->addSeconds(CreateSuggestionAction::PENDING_TTL_SECONDS),
    ])->save();

    return $suggestion;
}

it('queues a reconcile poll for every take still in flight after 2 minutes', function (): void {
    $stale = [takeAged('queued', 3), takeAged('processing', 10), takeAged('pending', 5)];
    $fresh = takeAged('processing', 1);
    $done = takeAged('succeeded', 30);

    // Flags alone never stop the sweep: a malformed webhook (invalid_snapshot)
    // or 32 min of 429/5xx (reconcile_exhausted) leave the take in flight.
    foreach ([AttentionReason::InvalidSnapshot, AttentionReason::ReconcileExhausted, AttentionReason::OverEstimate] as $reason) {
        $take = takeAged('processing', 40);
        $take->forceFill(['attention_reason' => $reason])->save();
        $stale[] = $take;
    }

    $notFound = takeAged('processing', 40);
    $noRates = takeAged('processing', 40);
    Queue::fake();
    Cache::flush();
    // Definitive stops recorded by the reconcile action (404, fetched generation without rates).
    ReconcileStopMarker::mark($notFound->id, ReconcileStatus::NotFound);
    ReconcileStopMarker::mark($noRates->id, ReconcileStatus::Invalid);

    $result = sweep();

    expect($result->ran)->toBeTrue()->and($result->reconcilesDispatched)->toBe(count($stale));
    foreach ($stale as $take) {
        Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id);
    }
    foreach ([$fresh, $done, $notFound, $noRates] as $take) {
        Queue::assertNotPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id);
    }
});

it('keeps sweeping a take a malformed webhook flagged invalid_snapshot', function (): void {
    PixelforgeWebhook::configure();
    $take = takeAged('processing', 5);
    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'succeeded', overrides: ['usage' => null]))
        ->assertExactJson(['status' => 'invalid_snapshot']);
    expect($take->refresh()->attention_reason)->toBe(AttentionReason::InvalidSnapshot);
    Queue::fake();
    Cache::flush();

    sweep();

    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id);
});

it('queues no polls while Pixelforge is not configured', function (): void {
    takeAged('processing', 5);
    config(['pixelforge.api_key' => '']);
    Queue::fake();
    Cache::flush();

    expect(sweep()->reconcilesDispatched)->toBe(0);
    Queue::assertNotPushed(ReconcileTakeJob::class);
});

it('runs at most once per throttle window', function (): void {
    $this->freezeTime();
    takeAged('processing', 5);
    Queue::fake();
    Cache::flush();

    expect(sweep()->ran)->toBeTrue();

    $this->travel(SweepStaleTakesAction::THROTTLE_SECONDS - 1)->seconds();
    expect(sweep()->ran)->toBeFalse();

    $this->travel(2)->seconds();
    expect(sweep()->ran)->toBeTrue();
});

it('ignores the throttle when forced', function (): void {
    Queue::fake();

    expect(sweep()->ran)->toBeTrue()
        ->and(sweep(force: true)->ran)->toBeTrue();
});

it('expires a suggestion pending for more than 2 minutes and releases its hold', function (): void {
    Queue::fake();
    $stale = pendingSuggestion(3);
    $fresh = pendingSuggestion(1);
    $proposed = Suggestion::factory()->proposed()->create(['created_at' => now()->subMinutes(10), 'expires_at' => now()->subMinutes(8)]);

    $result = sweep();

    expect($result->suggestionsExpired)->toBe(1)
        ->and($stale->refresh()->status)->toBe(SuggestionStatus::Failed)
        ->and($stale->failure_reason)->toBe(SuggestionFailureReason::Timeout)
        ->and($stale->usageRecord()->sole()->status)->toBe(UsageStatus::Voided)
        ->and($stale->customer()->sole()->held_nanos)->toBe(0)
        ->and($fresh->refresh()->status)->toBe(SuggestionStatus::Pending)
        ->and($fresh->usageRecord()->sole()->status)->toBe(UsageStatus::Open)
        ->and($proposed->refresh()->status)->toBe(SuggestionStatus::Proposed);
    Billing::assertCachedColumnsMatch($stale->customer()->sole());
});

/** Runs the sweep job the middleware queued, as a worker would. */
function runQueuedSweep(): void
{
    $sweeps = Queue::pushed(SweepStaleTakesJob::class);
    expect($sweeps)->toHaveCount(1);
    app()->call([$sweeps->first(), 'handle']);
}

it('queues one sweep after a customer page load, throttled', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $customer = Customer::factory()->withRole()->create();
    $take = takeAged('processing', 5);
    Queue::fake();
    Cache::flush();

    $this->actingAs($customer, 'customer')->get(route('billing.show'))->assertOk();
    $this->actingAs($customer, 'customer')->get(route('billing.show'))->assertOk();

    runQueuedSweep();
    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id);
});

it('queues one sweep after an admin console page load', function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
    $take = takeAged('processing', 5);
    Queue::fake();
    Cache::flush();

    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())->get('/admin')->assertOk();

    runQueuedSweep();
    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id);
});

it('does not record the sweep as the admin who opened the console (FI-08, FI-09)', function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
    $stale = pendingSuggestion(3);
    Queue::fake();
    Cache::flush();

    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())->get('/admin')->assertOk();

    // Worst case, as with the `sync` driver: the job runs in the same process
    // right after the console response, while Filament still says it is serving.
    expect(Filament::isServing())->toBeTrue();
    runQueuedSweep();

    expect($stale->refresh()->status)->toBe(SuggestionStatus::Failed)
        ->and(Activity::query()->where('log_name', 'admin')->count())->toBe(0);
});

it('does not sweep for a guest page load or a write', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    takeAged('processing', 5);
    Queue::fake();
    Cache::flush();

    $this->get('/')->assertOk();
    $this->actingAs(Customer::factory()->withRole()->create(), 'customer')
        ->post(route('billing.purchases.store'), []);

    Queue::assertNotPushed(SweepStaleTakesJob::class);
    Queue::assertNotPushed(ReconcileTakeJob::class);
});

it('never lets definitive stops crowd out newer stale takes', function (): void {
    $stopped = Take::factory()->processing()->count(SweepStaleTakesAction::BATCH)->create(['created_at' => now()->subHour()]);
    $newer = takeAged('processing', 5);
    Queue::fake();
    Cache::flush();
    foreach ($stopped as $take) {
        ReconcileStopMarker::mark($take->id, ReconcileStatus::NotFound);
    }

    expect(sweep()->reconcilesDispatched)->toBe(1);
    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $newer->id);
});

it('sweeps stale takes from the command', function (): void {
    takeAged('processing', 5);
    Queue::fake();
    Cache::lock(SweepStaleTakesAction::LOCK, 120)->get();

    $this->artisan('takes:reconcile', ['--stale' => true])
        ->expectsOutputToContain('Queued 1 reconcile polls')
        ->assertSuccessful();

    Queue::assertPushed(ReconcileTakeJob::class, 1);
});
