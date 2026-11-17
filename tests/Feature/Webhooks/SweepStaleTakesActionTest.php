<?php

declare(strict_types=1);

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Takes\SweepStaleTakesAction;
use App\DTOs\Takes\SweepResultDTO;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Enums\Suggestions\SuggestionStatus;
use App\Enums\Takes\AttentionReason;
use App\Events\TakeUpdated;
use App\Http\Middleware\SweepStaleTakes;
use App\Jobs\ReconcileTakeJob;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Billing;
use Tests\Support\Takes;

/*
| SweepStaleTakesAction (D-036, W-16): page loads restart lost reconcile
| chains and expire stuck suggestions, at most once per 2 minutes.
*/

beforeEach(function (): void {
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

/** A pending suggestion with its AI hold open, created $minutes ago. */
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
    $suggestion->forceFill(['created_at' => now()->subMinutes($minutes)])->save();

    return $suggestion;
}

it('queues a reconcile poll for every take still in flight after 2 minutes', function (): void {
    $stale = [takeAged('queued', 3), takeAged('processing', 10), takeAged('pending', 5)];
    $fresh = takeAged('processing', 1);
    $done = takeAged('succeeded', 30);
    $exhausted = takeAged('processing', 40);
    $exhausted->forceFill(['attention_reason' => AttentionReason::ReconcileExhausted])->save();
    Queue::fake();
    Cache::flush();

    $result = sweep();

    expect($result->ran)->toBeTrue()->and($result->reconcilesDispatched)->toBe(3);
    foreach ($stale as $take) {
        Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id);
    }
    foreach ([$fresh, $done, $exhausted] as $take) {
        Queue::assertNotPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id);
    }
});

it('runs at most once per throttle window', function (): void {
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
    $proposed = Suggestion::factory()->proposed()->create(['created_at' => now()->subMinutes(10)]);

    $result = sweep();

    expect($result->suggestionsExpired)->toBe(1)
        ->and($stale->refresh()->status)->toBe(SuggestionStatus::Failed)
        ->and($stale->failure_reason)->toBe('timeout')
        ->and($stale->usageRecord()->sole()->status)->toBe(UsageStatus::Voided)
        ->and($stale->customer()->sole()->held_nanos)->toBe(0)
        ->and($fresh->refresh()->status)->toBe(SuggestionStatus::Pending)
        ->and($fresh->usageRecord()->sole()->status)->toBe(UsageStatus::Open)
        ->and($proposed->refresh()->status)->toBe(SuggestionStatus::Proposed);
    Billing::assertCachedColumnsMatch($stale->customer()->sole());
});

it('sweeps after a signed-in page load has been answered', function (): void {
    takeAged('processing', 5);
    Queue::fake();
    Cache::flush();

    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => User::factory()->make());

    app(SweepStaleTakes::class)->terminate($request, new Response);

    Queue::assertPushed(ReconcileTakeJob::class, 1);
});

it('does not sweep for guests or writes', function (string $method, bool $signedIn): void {
    takeAged('processing', 5);
    Queue::fake();
    Cache::flush();

    $request = Request::create('/dashboard', $method);
    $request->setUserResolver(fn () => $signedIn ? User::factory()->make() : null);

    app(SweepStaleTakes::class)->terminate($request, new Response);

    Queue::assertNothingPushed();
})->with([['GET', false], ['POST', true]]);

it('sweeps stale takes from the command', function (): void {
    takeAged('processing', 5);
    Queue::fake();
    Cache::lock(SweepStaleTakesAction::LOCK, 120)->get();

    $this->artisan('takes:reconcile', ['--stale' => true])
        ->expectsOutputToContain('Queued 1 reconcile polls')
        ->assertSuccessful();

    Queue::assertPushed(ReconcileTakeJob::class, 1);
});
