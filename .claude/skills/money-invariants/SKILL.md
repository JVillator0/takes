---
name: money-invariants
description: "Use before touching anything that moves customer money in Takes: usage records, holds, the ledger, balances, purchases, credits, provider costs or a new billable provider (spec 3-03). Lists the invariants, the only allowed call pattern and how to add a provider without changing billing."
---

# Money invariants (spec 3-03)

Correct money beats everything. Read `.claude/specs/3-domain-foundation/03-SPEC.md` for the full rules; this is the checklist.

## The invariants

1. **Integer nanodollars only** (D-002): `int` / `bigint`, wrapped in `App\Support\Money\Nanodollars`. No float, `/`, `round(`, `floatval(`, `number_format(` in `app/Support/Money` or `app/Actions/Billing` (an arch test scans for them). Divide with `intdiv`.
2. **Rates are integer nanodollars per 1M tokens** by kind (`RatesSnapshotDTO`, e.g. Pixelforge output 40_000_000_000). Never multiply by a decimal string.
3. **cost = ceil(Σ units × rate / 1_000_000)** (`ProviderCost::calculate`); **charge = cost.withMarkup(markup_bps)** = ceil(cost × 12_500 / 10_000). The ceil logs `billing.cost_rounded_up`; with current rates it never fires.
4. **Ledger = real money only** (D-024): `purchase`, `promo_grant`, `charge`, `adjustment`. Holds are not rows: an open usage's `max_charge_nanos` *is* the hold.
5. `customers.balance_nanos` = Σ ledger; `customers.held_nanos` = Σ open `max_charge_nanos`; **available = balance − held** (may be negative after an over-estimate).
6. **Writers:** `RecordLedgerEntryAction` is the only writer of `ledger_entries` and `balance_nanos`. `held_nanos` changes only in `OpenUsageAction`, `SettleUsageAction`, `VoidUsageAction`. Ledger rows are never updated or deleted; a correction is an `adjustment`.
7. **Exactly once:** a usage is settled or voided once. Settle/Void on a non-open usage is a silent no-op (webhook replays are normal). The partial unique index on `charge.usage_record_id` is the backstop.
8. **Failed = free:** void releases the hold, cost 0, charge 0.
9. **Lock order, the only one:** take|suggestion → customer. The money actions lock the customer row only and re-read the usage under it. Never lock a usage row separately.
10. **Caller's transaction:** Open/Settle/Void/Record throw `TransactionRequiredException` at transaction level 0, so the state change and the money move commit together.

## Call pattern for any billable provider call

```php
// 1. Before calling the provider (same transaction as creating/locking the billable):
DB::transaction(function () use ($take, $estimate, $rates) {
    $take = Take::query()->lockForUpdate()->findOrFail($take->id);       // billable first
    $usage = $openUsage->execute($take, Provider::Pixelforge, $estimate, $rates); // may throw InsufficientCreditsException
});

// 2a. Provider succeeded (webhook, reconcile, create response):
DB::transaction(fn () => $settleUsage->execute($usage, $units, $appliedRates, $completedAt, $externalId, $reportedCost));

// 2b. Provider failed or rejected:
DB::transaction(fn () => $voidUsage->execute($usage));
```

`$appliedRates` are the rates the provider actually charged (D-055); they overwrite the open snapshot. `billing_date` = UTC date of `$completedAt`.

## Adding a billable provider (§8)

1. `App\Enums\Billing\Provider`: new case (+ the `usage_records_provider_check` CHECK).
2. Units kinds: reuse `UsageKind` cases; a new kind = new enum case + a `<kind>_tokens` column.
3. Rates source: map the provider's prices into `RatesSnapshotDTO` (integer nanodollars per 1M) — from its API or from `config/ai-pricing.php` strings parsed with `Nanodollars::perMillionFromDecimalString`.
4. Estimator returning `EstimateDTO::fromCosts($minCost, $maxCost)`; the hold is `maxCharge`.
5. Wrap the call site in Open → Settle/Void as above; map the provider's usage object into `UsageUnitsDTO`.
6. The billable model goes in the morph map (`AppServiceProvider`) and the `billable_type` CHECK.

Nothing else in billing changes. If you find yourself editing the ledger, the cached columns or the cost formula, stop and ask.

## Checks before you ship

- Tests: happy path, settle twice, void after settle, settle after void, outside a transaction, actual > hold, and the cached columns match (`Tests\Support\Billing::assertCachedColumnsMatch`).
- `php artisan billing:verify` exits 0 on your test data; it exits 1 on any drift.
- Concurrency tests (`--group=concurrency`, `tests/Committed`) run serially, never with `--parallel`.
