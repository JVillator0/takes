<?php

declare(strict_types=1);

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Suggestions\ExpireSuggestionAction;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Enums\Suggestions\SuggestionFailureReason;
use App\Enums\Suggestions\SuggestionStatus;
use App\Models\Suggestion;
use Illuminate\Support\Facades\DB;
use Tests\Support\Billing;

/*
 * ExpireSuggestionAction (spec 8 "Expiry"): a pending suggestion past
 * expires_at is failed (timeout) and its hold released; anything else is
 * left alone. Called lazily by CreateSuggestionAction and by spec 5's sweeper.
 */

beforeEach(function (): void {
    $this->customer = Billing::fundedCustomer(1_000_000_000);
    $this->suggestion = Suggestion::factory()->create([
        'customer_id' => $this->customer->id,
        'expires_at' => now()->subSecond(),
    ]);
    DB::transaction(fn () => app(OpenUsageAction::class)->execute(
        Suggestion::query()->lockForUpdate()->findOrFail($this->suggestion->id), Provider::Anthropic, Billing::estimate(400_000), Billing::anthropicRates(),
    ));
});

it('fails an expired pending suggestion and voids its usage', function (): void {
    expect(app(ExpireSuggestionAction::class)->execute($this->suggestion))->toBeTrue();

    $suggestion = $this->suggestion->refresh();

    expect($suggestion->status)->toBe(SuggestionStatus::Failed)
        ->and($suggestion->failure_reason)->toBe(SuggestionFailureReason::Timeout)
        ->and($suggestion->usageRecord()->firstOrFail()->status)->toBe(UsageStatus::Voided)
        ->and($this->customer->refresh()->held_nanos)->toBe(0);

    Billing::assertCachedColumnsMatch($this->customer);
});

it('is idempotent', function (): void {
    app(ExpireSuggestionAction::class)->execute($this->suggestion);

    expect(app(ExpireSuggestionAction::class)->execute($this->suggestion))->toBeFalse();
});

it('leaves a pending suggestion that has not expired yet alone', function (): void {
    $this->suggestion->forceFill(['expires_at' => now()->addMinute()])->save();

    expect(app(ExpireSuggestionAction::class)->execute($this->suggestion))->toBeFalse()
        ->and($this->suggestion->refresh()->status)->toBe(SuggestionStatus::Pending)
        ->and($this->customer->refresh()->held_nanos)->toBe(500_000);
});

it('leaves a suggestion that already moved on alone', function (): void {
    $this->suggestion->forceFill(['status' => SuggestionStatus::Proposed])->save();

    expect(app(ExpireSuggestionAction::class)->execute($this->suggestion))->toBeFalse()
        ->and($this->suggestion->refresh()->status)->toBe(SuggestionStatus::Proposed);
});

it('re-checks under the lock: a stale pending copy of a row that moved on changes nothing', function (): void {
    $stale = $this->suggestion;
    // Another process proposed it after the sweeper loaded its copy.
    Suggestion::query()->whereKey($stale->id)->update(['status' => SuggestionStatus::Proposed->value]);
    $before = $this->customer->refresh();

    expect($stale->status)->toBe(SuggestionStatus::Pending)
        ->and(app(ExpireSuggestionAction::class)->execute($stale))->toBeFalse();

    $fresh = $stale->refresh();
    expect($fresh->status)->toBe(SuggestionStatus::Proposed)
        ->and($fresh->failure_reason)->toBeNull()
        ->and($fresh->usageRecord()->firstOrFail()->status)->toBe(UsageStatus::Open)
        ->and($this->customer->refresh()->held_nanos)->toBe($before->held_nanos)
        ->and($this->customer->balance_nanos)->toBe($before->balance_nanos);
});
