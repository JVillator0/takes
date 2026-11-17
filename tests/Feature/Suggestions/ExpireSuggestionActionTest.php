<?php

declare(strict_types=1);

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Suggestions\ExpireSuggestionAction;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Enums\Suggestions\SuggestionStatus;
use App\Models\Suggestion;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\DB;
use Tests\Support\Billing;

/*
| Spec 8 "Expiry" through the sweeper (W-16, M-10, M-13): the status is
| re-checked under the suggestion lock, so a stale copy never expires a
| suggestion that moved on.
*/

function pendingSuggestionWithHold(): Suggestion
{
    $customer = Billing::fundedCustomer();
    $suggestion = Suggestion::factory()->create(['customer_id' => $customer->id]);

    DB::transaction(fn (): UsageRecord => app(OpenUsageAction::class)->execute(
        $suggestion,
        Provider::Anthropic,
        Billing::estimate(5_000_000),
        Billing::anthropicRates(),
    ));

    return $suggestion->refresh();
}

it('expires a pending suggestion and releases its hold', function (): void {
    $suggestion = pendingSuggestionWithHold();

    expect(app(ExpireSuggestionAction::class)->execute($suggestion))->toBeTrue()
        ->and($suggestion->refresh()->status)->toBe(SuggestionStatus::Failed)
        ->and($suggestion->failure_reason)->toBe(ExpireSuggestionAction::FAILURE_REASON)
        ->and($suggestion->usageRecord()->sole()->status)->toBe(UsageStatus::Voided)
        ->and($suggestion->customer()->sole()->held_nanos)->toBe(0);
});

it('leaves a suggestion alone when its row moved on after the stale copy was read', function (): void {
    $stale = pendingSuggestionWithHold();
    $customer = $stale->customer()->sole();
    // Another process proposed it after the sweeper loaded its copy.
    Suggestion::query()->whereKey($stale->id)->update(['status' => SuggestionStatus::Proposed->value]);

    expect($stale->status)->toBe(SuggestionStatus::Pending)
        ->and(app(ExpireSuggestionAction::class)->execute($stale))->toBeFalse();

    $fresh = $stale->refresh();
    expect($fresh->status)->toBe(SuggestionStatus::Proposed)
        ->and($fresh->failure_reason)->toBeNull()
        ->and($fresh->usageRecord()->sole()->status)->toBe(UsageStatus::Open)
        ->and($fresh->customer()->sole()->held_nanos)->toBe($customer->held_nanos)
        ->and($fresh->customer()->sole()->balance_nanos)->toBe($customer->balance_nanos);
});
