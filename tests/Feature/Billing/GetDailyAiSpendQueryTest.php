<?php

declare(strict_types=1);

use App\Models\UsageRecord;
use App\Queries\Billing\GetDailyAiSpendQuery;

/*
 * The global daily AI budget (spec 8 Guardrails, `ai_paused`): Σ settled
 * provider cost today + Σ absorbed cost of voided calls the provider billed
 * today (issue #122) + Σ max cost of open usages.
 */

it('adds the absorbed cost of today\'s voided calls to settled cost and open holds', function (): void {
    UsageRecord::factory()->forSuggestion()->settled(254_000)->create();
    UsageRecord::factory()->forSuggestion()->voided(149_500)->create();
    UsageRecord::factory()->forSuggestion()->create(); // open: max cost 400,000
    // Nothing billed: an error before any token, or an expiry.
    UsageRecord::factory()->forSuggestion()->voided()->create();

    expect(app(GetDailyAiSpendQuery::class)->execute()->toInt())->toBe(254_000 + 149_500 + 400_000);
});

it('counts absorbed cost only on the UTC day the call completed, and only for Anthropic', function (): void {
    $this->travelTo(now()->utc()->startOfDay()->subSecond());
    UsageRecord::factory()->forSuggestion()->voided(149_500)->create();
    UsageRecord::factory()->forSuggestion()->settled(254_000)->create();

    $this->travelTo(now()->utc()->addSecond());
    // Image cost is never part of the AI budget.
    UsageRecord::factory()->voided(1_000)->create();

    expect(app(GetDailyAiSpendQuery::class)->execute()->toInt())->toBe(0);
});
