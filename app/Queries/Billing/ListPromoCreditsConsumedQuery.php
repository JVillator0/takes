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
 * ledger rows, not with an SQL sum. Overdraft debt is modelled: a credit first repays it, and that repayment is
 * attributed to no charge, so a settled charge's figure never changes after the fact.
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
        // The customers with settled usage in the filter, as a subquery: no id list round-trips through PHP.
        $customers = UsageRecord::query()
            ->select('customer_id')
            ->where('status', UsageStatus::Settled->value)
            ->whereBetween('billing_date', [$filter->from->toDateString(), $filter->until->toDateString()])
            ->when($filter->customerId !== null, fn ($query) => $query->where('customer_id', $filter->customerId))
            ->when($filter->provider !== null, fn ($query) => $query->where('provider', $filter->provider?->value));

        $entries = LedgerEntry::query()
            ->toBase()
            ->leftJoin('usage_records', 'usage_records.id', '=', 'ledger_entries.usage_record_id')
            ->whereIn('ledger_entries.customer_id', $customers)
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
        // Per customer: promo credit, purchased credit and overdraft debt (balance = promo + purchased − debt).
        $promo = $purchased = $debt = 0;

        foreach ($entries as $entry) {
            if ($entry->customer_id !== $customer) {
                $customer = $entry->customer_id;
                $promo = $purchased = $debt = 0;
            }

            $amount = (int) $entry->amount_nanos;
            $type = LedgerEntryType::from((string) $entry->type);

            if ($amount > 0) {
                // Any credit repays an overdraft first (M-15); only the rest joins its pool.
                $repaid = min($debt, $amount);
                $debt -= $repaid;

                if ($type === LedgerEntryType::Purchase) {
                    $purchased += $amount - $repaid;
                } else {
                    $promo += $amount - $repaid;
                }

                continue;
            }

            // A charge or a negative adjustment: promo credit first, then purchased credit, the rest is debt.
            $owed = -$amount;
            $fromPromo = min($promo, $owed);
            $promo -= $fromPromo;
            $fromPurchased = min($purchased, $owed - $fromPromo);
            $purchased -= $fromPurchased;
            $debt += $owed - $fromPromo - $fromPurchased;

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
