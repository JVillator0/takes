<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\BillingReportFilterDTO;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\UsageStatus;
use App\Models\LedgerEntry;
use App\Models\UsageRecord;

/**
 * Promo/adjustment credits consumed (Billing › Reports, D-084): how much of each settled charge was paid from
 * promo grants and positive adjustments rather than purchases. The allocation is defined by D-084: per customer,
 * in ledger order, `promo_grant` and positive `adjustment` credits are spent before `purchase` credits, and a
 * negative adjustment takes back promo credit first. It walks each affected customer's whole ledger (the pool at
 * the first charge in range depends on everything before it), so it is computed here in integer PHP from the
 * ledger rows, not with an SQL sum.
 *
 * Returns the promo-paid nanodollars of the charges whose usage is settled and billed in the filter's range,
 * keyed "billing_date|customer_id|provider".
 */
final readonly class ListPromoCreditsConsumedQuery
{
    /**
     * @return array<string, int>
     */
    public function execute(BillingReportFilterDTO $filter): array
    {
        $customers = UsageRecord::query()
            ->where('status', UsageStatus::Settled->value)
            ->whereBetween('billing_date', [$filter->from->toDateString(), $filter->until->toDateString()])
            ->when($filter->customerId !== null, fn ($query) => $query->where('customer_id', $filter->customerId))
            ->when($filter->provider !== null, fn ($query) => $query->where('provider', $filter->provider?->value))
            ->distinct()
            ->pluck('customer_id');

        if ($customers->isEmpty()) {
            return [];
        }

        $entries = LedgerEntry::query()
            ->toBase()
            ->leftJoin('usage_records', 'usage_records.id', '=', 'ledger_entries.usage_record_id')
            ->whereIn('ledger_entries.customer_id', $customers->all())
            ->orderBy('ledger_entries.customer_id')
            ->orderBy('ledger_entries.id')
            ->select([
                'ledger_entries.customer_id',
                'ledger_entries.type',
                'ledger_entries.amount_nanos',
                'usage_records.billing_date',
                'usage_records.provider',
            ])
            ->cursor();

        $from = $filter->from->toDateString();
        $until = $filter->until->toDateString();
        $consumed = [];
        $customer = null;
        $promoPool = 0;

        foreach ($entries as $entry) {
            if ($entry->customer_id !== $customer) {
                $customer = $entry->customer_id;
                $promoPool = 0;
            }

            $amount = (int) $entry->amount_nanos;
            $type = LedgerEntryType::from((string) $entry->type);

            if ($type === LedgerEntryType::PromoGrant || ($type === LedgerEntryType::Adjustment && $amount > 0)) {
                $promoPool += $amount;

                continue;
            }

            if ($type === LedgerEntryType::Purchase) {
                continue;
            }

            // A charge, or a negative adjustment: spend promo credit first.
            $fromPromo = min(max($promoPool, 0), -$amount);
            $promoPool -= $fromPromo;

            if ($type !== LedgerEntryType::Charge || $fromPromo === 0 || $entry->billing_date === null) {
                continue;
            }

            $day = substr((string) $entry->billing_date, 0, 10);

            if ($day < $from || $day > $until || ($filter->provider !== null && $entry->provider !== $filter->provider->value)) {
                continue;
            }

            $key = $day.'|'.$entry->customer_id.'|'.$entry->provider;
            $consumed[$key] = ($consumed[$key] ?? 0) + $fromPromo;
        }

        return $consumed;
    }
}
