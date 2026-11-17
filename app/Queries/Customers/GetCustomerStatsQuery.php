<?php

declare(strict_types=1);

namespace App\Queries\Customers;

use App\DTOs\Customers\CustomerStatsDTO;
use App\Enums\Billing\UsageStatus;
use App\Models\Customer;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;

/**
 * The Customers header (A09): one aggregate over `customers` and one over `usage_records`.
 *
 * Avg spend / spending customer (30 d) = Σ settled `charge_nanos` with `billing_date` in the last 30 UTC days
 * (today included) ÷ the customers with at least one settled usage in that window, floored to the nanodollar;
 * null when nobody spent. It replaces A09's "per active customer": "active" is `last_active_at`, which does not
 * imply spend.
 */
final readonly class GetCustomerStatsQuery
{
    public const int SPEND_DAYS = 30;

    public function execute(): CustomerStatsDTO
    {
        $since = CarbonImmutable::now('UTC')->subDays(self::SPEND_DAYS - 1)->toDateString();

        $row = Customer::query()
            ->toBase()
            ->selectRaw('count(*) as customers')
            ->selectRaw('count(*) filter (where last_active_at >= ?) as active_today', [CarbonImmutable::now('UTC')->startOfDay()])
            ->selectRaw('coalesce(sum(balance_nanos), 0) as outstanding_nanos')
            ->selectRaw('coalesce(sum(held_nanos), 0) as holds_nanos')
            ->first();

        $usage = UsageRecord::query()
            ->toBase()
            ->selectRaw('count(*) filter (where status = ?) as open_usage_records', [UsageStatus::Open->value])
            ->selectRaw('coalesce(sum(charge_nanos) filter (where status = ? and billing_date >= ?), 0) as spend_nanos', [UsageStatus::Settled->value, $since])
            ->selectRaw('count(distinct customer_id) filter (where status = ? and billing_date >= ?) as spending_customers', [UsageStatus::Settled->value, $since])
            ->first();

        return CustomerStatsDTO::fromArray([
            'customers' => (int) ($row->customers ?? 0),
            'active_today' => (int) ($row->active_today ?? 0),
            'outstanding_nanos' => $row->outstanding_nanos ?? 0,
            'holds_nanos' => $row->holds_nanos ?? 0,
            'open_usage_records' => (int) ($usage->open_usage_records ?? 0),
            'average_spend_nanos' => $this->averageSpend($usage->spend_nanos ?? 0, (int) ($usage->spending_customers ?? 0)),
        ]);
    }

    private function averageSpend(int|string $spendNanos, int $spendingCustomers): ?int
    {
        return $spendingCustomers === 0 ? null : intdiv(Nanodollars::fromInt($spendNanos)->toInt(), $spendingCustomers);
    }
}
