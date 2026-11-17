<?php

declare(strict_types=1);

namespace App\DTOs\Customers;

use App\Support\Money\Nanodollars;

/**
 * Header stats of Customers (A09, spec 7 Definitions): how many customers, how many were active today (UTC), the
 * outstanding credits (Σ balance, the liability; held money is still the customer's), the active holds (Σ held,
 * with the count of open usage records) and the average spend per spending customer over the last 30 UTC days
 * (computed by GetCustomerStatsQuery; null when nobody spent).
 */
final readonly class CustomerStatsDTO
{
    public function __construct(
        public int $customers,
        public int $activeToday,
        public Nanodollars $outstandingCredits,
        public Nanodollars $activeHolds,
        public int $openUsageRecords,
        public ?Nanodollars $averageSpend,
    ) {}

    /**
     * @param  array{customers: int, active_today: int, outstanding_nanos: int|string|null, holds_nanos: int|string|null, open_usage_records: int, average_spend_nanos: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['customers'],
            $data['active_today'],
            Nanodollars::fromInt($data['outstanding_nanos'] ?? 0),
            Nanodollars::fromInt($data['holds_nanos'] ?? 0),
            $data['open_usage_records'],
            $data['average_spend_nanos'] === null ? null : Nanodollars::fromInt($data['average_spend_nanos']),
        );
    }
}
