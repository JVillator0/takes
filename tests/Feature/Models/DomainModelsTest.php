<?php

declare(strict_types=1);

use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\TakeSource;
use App\Enums\Takes\TakeStatus;
use App\Enums\Webhooks\WebhookOutcome;
use App\Events\TakeUpdated;
use App\Exceptions\Billing\ImmutableLedgerEntryException;
use App\Jobs\PersistTakeImageJob;
use App\Jobs\ReconcileTakeJob;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('builds valid rows from every factory and state', function (): void {
    $take = Take::factory()->create();

    Take::factory()->queued()->create();
    Take::factory()->processing()->create();
    Take::factory()->succeeded()->create();
    Take::factory()->failed(FailureCode::SubmitTimeout)->create();
    Take::factory()->remixOf($take)->create();
    Customer::factory()->demo()->create();
    Project::factory()->archived()->create();
    Suggestion::factory()->proposed()->create();
    UsageRecord::factory()->create();
    UsageRecord::factory()->settled()->create();
    UsageRecord::factory()->voided()->create();
    UsageRecord::factory()->forSuggestion()->create();
    Purchase::factory()->create();
    Purchase::factory()->pending()->create();
    LedgerEntry::factory()->welcome()->create();
    LedgerEntry::factory()->purchase()->create();
    LedgerEntry::factory()->charge()->create();
    LedgerEntry::factory()->adjustment(-250)->create();
    WebhookDelivery::factory()->create();
    WebhookDelivery::factory()->duplicate()->create();

    expect(Take::query()->count())->toBeGreaterThanOrEqual(7)
        ->and(LedgerEntry::query()->count())->toBe(4);
});

it('casts take columns to enums, arrays and immutable dates', function (): void {
    $take = Take::factory()->succeeded()->create(['reference_images' => ['https://example.test/ref.png']])->refresh();

    expect($take->status)->toBe(TakeStatus::Succeeded)
        ->and($take->source)->toBe(TakeSource::Composer)
        ->and($take->reference_images)->toBe(['https://example.test/ref.png'])
        ->and($take->completed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($take->getKey())->toBeString()->toHaveLength(26);
});

it('defaults a new take to pending with no reference images', function (): void {
    $take = new Take;

    expect($take->status)->toBe(TakeStatus::Pending)
        ->and($take->reference_images)->toBe([])
        ->and($take->submit_attempts)->toBe(0);
});

it('relates takes to their customer, project, lineage and suggestion', function (): void {
    $parent = Take::factory()->succeeded()->create();
    $remix = Take::factory()->remixOf($parent)->create();
    $suggestion = Suggestion::factory()->create([
        'customer_id' => $parent->customer_id,
        'project_id' => $parent->project_id,
        'base_take_id' => $parent->id,
    ]);
    $fromAi = Take::factory()->create([
        'customer_id' => $parent->customer_id,
        'project_id' => $parent->project_id,
        'parent_take_id' => $parent->id,
        'suggestion_id' => $suggestion->id,
        'source' => TakeSource::AiChange,
    ]);

    expect($remix->parent?->is($parent))->toBeTrue()
        ->and($remix->project->is($parent->project))->toBeTrue()
        ->and($parent->remixes()->count())->toBe(2)
        ->and($parent->customer->takes()->count())->toBe(3)
        ->and($fromAi->suggestion?->is($suggestion))->toBeTrue()
        ->and($suggestion->take?->is($fromAi))->toBeTrue()
        ->and($suggestion->baseTake->is($parent))->toBeTrue();
});

it('stores morph aliases, not class names, on usage records', function (): void {
    $take = Take::factory()->create();
    $usage = UsageRecord::factory()->create(['customer_id' => $take->customer_id, 'billable_id' => $take->id]);
    $aiUsage = UsageRecord::factory()->forSuggestion()->create();

    expect(DB::table('usage_records')->where('id', $usage->id)->value('billable_type'))->toBe('take')
        ->and($usage->billable)->toBeInstanceOf(Take::class)
        ->and($take->usageRecord?->is($usage))->toBeTrue()
        ->and($aiUsage->billable)->toBeInstanceOf(Suggestion::class)
        ->and($aiUsage->provider)->toBe(Provider::Anthropic)
        ->and($usage->status)->toBe(UsageStatus::Open)
        ->and($usage->flags)->toBe([]);
});

it('computes available as balance minus held, possibly negative', function (): void {
    $customer = Customer::factory()->withCachedBalance(1_000_000_000, 467_343_750)->create();

    expect($customer->available()->toInt())->toBe(532_656_250)
        ->and($customer->balance()->toInt())->toBe(1_000_000_000)
        ->and($customer->held()->toInt())->toBe(467_343_750);

    $overdrawn = Customer::factory()->withCachedBalance(100, 250)->create();

    expect($overdrawn->available()->toInt())->toBe(-150)
        ->and($overdrawn->available()->isNegative())->toBeTrue()
        ->and((new Customer)->available()->isZero())->toBeTrue();
});

it('hides credentials when a customer is serialized', function (): void {
    expect(Customer::factory()->create()->toArray())->not->toHaveKeys(['password', 'remember_token']);
});

it('refuses mass assignment: actions use forceFill', function (): void {
    Project::create(['name' => 'Nope']);
})->throws(MassAssignmentException::class);

it('links ledger entries to their usage record and purchase', function (): void {
    $charge = LedgerEntry::factory()->charge()->create();
    $credit = LedgerEntry::factory()->purchase()->create();

    expect($charge->type)->toBe(LedgerEntryType::Charge)
        ->and($charge->usageRecord?->chargeEntry?->is($charge))->toBeTrue()
        ->and($credit->purchase?->ledgerEntry?->is($credit))->toBeTrue()
        ->and($credit->created_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('never updates a ledger entry', function (): void {
    $entry = LedgerEntry::factory()->welcome()->create();

    $entry->forceFill(['amount_nanos' => 2_000_000_000])->save();
})->throws(ImmutableLedgerEntryException::class);

it('never deletes a ledger entry', function (): void {
    $entry = LedgerEntry::factory()->welcome()->create();

    expect(fn () => $entry->delete())->toThrow(ImmutableLedgerEntryException::class)
        ->and(LedgerEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
});

it('records webhook deliveries with their outcome', function (): void {
    $delivery = WebhookDelivery::factory()->duplicate()->create();

    expect($delivery->outcome)->toBe(WebhookOutcome::Duplicate)
        ->and($delivery->webhookEvent?->event_id)->toBe($delivery->event_id)
        ->and(WebhookEvent::query()->firstOrFail()->deliveries()->count())->toBe(1);
});

it('ships the job and event stubs with their final signatures (D-068)', function (): void {
    Queue::fake();
    Event::fake();
    $take = Take::factory()->create();

    ReconcileTakeJob::dispatch($take->id);
    PersistTakeImageJob::dispatch($take->id);
    TakeUpdated::dispatch($take);

    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id);
    Queue::assertPushed(PersistTakeImageJob::class, fn (PersistTakeImageJob $job): bool => $job->takeId === $take->id);
    Event::assertDispatched(TakeUpdated::class, fn (TakeUpdated $event): bool => $event->take->is($take));
});
