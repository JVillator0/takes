<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Billing\UsageStatus;
use App\Enums\Suggestions\SuggestionStatus;
use App\Enums\Takes\TakeStatus;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use App\Support\Money\TokenCost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The money invariants of spec 3-03 (D-024, D-059), each as one query over
 * the whole dataset. Returns one human-readable line per violation; an empty
 * list means the ledger, the cached columns and the usage records agree.
 * Used by `billing:verify` (specs 9, 10). Unbounded, it scans every row
 * (settled amounts are re-priced in PHP), which suits the CLI. A page load
 * (spec 7's ledger-drift widget) passes `$repriceSince`: the checks that are
 * one SQL aggregate still cover every row, and only the settled usages billed
 * on or after that day are re-priced, so the PHP work is bounded by the window,
 * not by history (issue #102).
 */
final readonly class ListLedgerInvariantViolationsQuery
{
    private TokenCost $tokenCost;

    public function __construct()
    {
        // Re-pricing history must not re-log the rounding warning that
        // settlement already logged once, with its usage and customer ids.
        $this->tokenCost = new TokenCost(new NullLogger);
    }

    /**
     * @param  CarbonImmutable|null  $repriceSince  first UTC billing day whose settled usages are re-priced; null = all of them
     * @return list<string>
     */
    public function execute(?CarbonImmutable $repriceSince = null): array
    {
        return [
            ...$this->balanceDrift(),
            ...$this->heldDrift(),
            ...$this->chargeEntries(),
            ...$this->purchaseEntries(),
            ...$this->openUsageOnTerminalTake(),
            ...$this->openUsageOnTerminalSuggestion(),
            ...$this->settledAmounts($repriceSince),
        ];
    }

    /**
     * customers.balance_nanos = Σ ledger amount_nanos.
     *
     * @return list<string>
     */
    private function balanceDrift(): array
    {
        return array_values(DB::table('customers')
            ->leftJoin('ledger_entries', 'ledger_entries.customer_id', '=', 'customers.id')
            ->groupBy('customers.id', 'customers.balance_nanos')
            ->havingRaw('customers.balance_nanos <> coalesce(sum(ledger_entries.amount_nanos), 0)')
            ->orderBy('customers.id')
            ->selectRaw('customers.id, customers.balance_nanos, coalesce(sum(ledger_entries.amount_nanos), 0) as expected')
            ->get()
            ->map(fn (object $row): string => "customer {$row->id}: balance_nanos {$row->balance_nanos} but Σ ledger is {$row->expected}")
            ->all());
    }

    /**
     * customers.held_nanos = Σ max_charge_nanos of the customer's open usages.
     *
     * @return list<string>
     */
    private function heldDrift(): array
    {
        return array_values(DB::table('customers')
            ->leftJoin('usage_records', function ($join): void {
                $join->on('usage_records.customer_id', '=', 'customers.id')
                    ->where('usage_records.status', UsageStatus::Open->value);
            })
            ->groupBy('customers.id', 'customers.held_nanos')
            ->havingRaw('customers.held_nanos <> coalesce(sum(usage_records.max_charge_nanos), 0)')
            ->orderBy('customers.id')
            ->selectRaw('customers.id, customers.held_nanos, coalesce(sum(usage_records.max_charge_nanos), 0) as expected')
            ->get()
            ->map(fn (object $row): string => "customer {$row->id}: held_nanos {$row->held_nanos} but Σ open holds is {$row->expected}")
            ->all());
    }

    /**
     * A settled usage with a charge has exactly one charge entry for −charge,
     * on the same customer; any other usage has none (exactly-once).
     *
     * @return list<string>
     */
    private function chargeEntries(): array
    {
        return array_values(DB::table('usage_records')
            ->leftJoin('ledger_entries', function ($join): void {
                $join->on('ledger_entries.usage_record_id', '=', 'usage_records.id')
                    ->where('ledger_entries.type', 'charge');
            })
            ->groupBy('usage_records.id', 'usage_records.status', 'usage_records.charge_nanos', 'usage_records.customer_id')
            ->havingRaw(<<<'SQL'
                CASE WHEN usage_records.status = 'settled' AND usage_records.charge_nanos > 0
                    THEN count(ledger_entries.id) <> 1
                        OR sum(ledger_entries.amount_nanos) <> -usage_records.charge_nanos
                        OR bool_or(ledger_entries.customer_id <> usage_records.customer_id)
                    ELSE count(ledger_entries.id) <> 0
                END
                SQL)
            ->orderBy('usage_records.id')
            ->selectRaw('usage_records.id, usage_records.status, usage_records.charge_nanos, count(ledger_entries.id) as entries, coalesce(sum(ledger_entries.amount_nanos), 0) as charged')
            ->get()
            ->map(fn (object $row): string => "usage {$row->id} ({$row->status}, charge_nanos ".($row->charge_nanos ?? 'null')."): {$row->entries} charge entries totalling {$row->charged}")
            ->all());
    }

    /**
     * A paid purchase has exactly one purchase entry for its amount; others have none.
     *
     * @return list<string>
     */
    private function purchaseEntries(): array
    {
        return array_values(DB::table('purchases')
            ->leftJoin('ledger_entries', function ($join): void {
                $join->on('ledger_entries.purchase_id', '=', 'purchases.id')
                    ->where('ledger_entries.type', 'purchase');
            })
            ->groupBy('purchases.id', 'purchases.status', 'purchases.amount_nanos', 'purchases.customer_id')
            ->havingRaw(<<<'SQL'
                CASE WHEN purchases.status = 'paid'
                    THEN count(ledger_entries.id) <> 1
                        OR sum(ledger_entries.amount_nanos) <> purchases.amount_nanos
                        OR bool_or(ledger_entries.customer_id <> purchases.customer_id)
                    ELSE count(ledger_entries.id) <> 0
                END
                SQL)
            ->orderBy('purchases.id')
            ->selectRaw('purchases.id, purchases.status, count(ledger_entries.id) as entries, coalesce(sum(ledger_entries.amount_nanos), 0) as credited')
            ->get()
            ->map(fn (object $row): string => "purchase {$row->id} ({$row->status}): {$row->entries} purchase entries totalling {$row->credited}")
            ->all());
    }

    /**
     * A terminal take has released its hold: its usage is settled or voided.
     *
     * @return list<string>
     */
    private function openUsageOnTerminalTake(): array
    {
        return array_values(DB::table('usage_records')
            ->join('takes', function ($join): void {
                $join->on('takes.id', '=', 'usage_records.billable_id')
                    ->where('usage_records.billable_type', 'take');
            })
            ->where('usage_records.status', UsageStatus::Open->value)
            ->whereIn('takes.status', array_map(
                fn (TakeStatus $status): string => $status->value,
                array_filter(TakeStatus::cases(), fn (TakeStatus $status): bool => $status->isTerminal()),
            ))
            ->orderBy('usage_records.id')
            ->select('usage_records.id', 'takes.id as take_id', 'takes.status as take_status')
            ->get()
            ->map(fn (object $row): string => "usage {$row->id}: still open but take {$row->take_id} is {$row->take_status}")
            ->all());
    }

    /**
     * A suggestion whose AI call is over (every status but `pending`, spec 8)
     * has released its hold: its usage is settled or voided (issue #102).
     *
     * @return list<string>
     */
    private function openUsageOnTerminalSuggestion(): array
    {
        return array_values(DB::table('usage_records')
            ->join('suggestions', function ($join): void {
                $join->on('suggestions.id', '=', 'usage_records.billable_id')
                    ->where('usage_records.billable_type', 'suggestion');
            })
            ->where('usage_records.status', UsageStatus::Open->value)
            ->whereIn('suggestions.status', array_map(
                fn (SuggestionStatus $status): string => $status->value,
                array_filter(SuggestionStatus::cases(), fn (SuggestionStatus $status): bool => $status->isTerminal()),
            ))
            ->orderBy('usage_records.id')
            ->select('usage_records.id', 'suggestions.id as suggestion_id', 'suggestions.status as suggestion_status')
            ->get()
            ->map(fn (object $row): string => "usage {$row->id}: still open but suggestion {$row->suggestion_id} is {$row->suggestion_status}")
            ->all());
    }

    /**
     * A settled usage's amounts follow from its own units and rates:
     * cost = ceil(Σ units × rate / 1M), charge = cost with its markup.
     *
     * @return list<string>
     */
    private function settledAmounts(?CarbonImmutable $since): array
    {
        $violations = [];

        UsageRecord::query()
            ->where('status', UsageStatus::Settled)
            ->when($since !== null, fn ($query) => $query->where('billing_date', '>=', $since?->toDateString()))
            ->orderBy('id')
            ->lazyById()
            ->each(function (UsageRecord $usage) use (&$violations): void {
                try {
                    $cost = $this->tokenCost->compute(
                        new UsageUnitsDTO($usage->units ?? [])->tokens,
                        RatesSnapshotDTO::fromStored($usage->rates)->toArray(),
                    );
                } catch (Throwable $e) {
                    $violations[] = "usage {$usage->id}: units or rates cannot be priced ({$e->getMessage()})";

                    return;
                }

                if ($usage->provider_cost_nanos !== $cost->toInt()) {
                    $violations[] = "usage {$usage->id}: provider_cost_nanos {$usage->provider_cost_nanos} but units × rates is {$cost->toInt()}";
                }

                $expectedCharge = Nanodollars::fromInt($usage->provider_cost_nanos ?? 0)->withMarkup($usage->markup_bps)->toInt();

                if ($usage->charge_nanos !== $expectedCharge) {
                    $violations[] = "usage {$usage->id}: charge_nanos {$usage->charge_nanos} but provider_cost_nanos + {$usage->markup_bps} bps is {$expectedCharge}";
                }
            });

        return $violations;
    }
}
