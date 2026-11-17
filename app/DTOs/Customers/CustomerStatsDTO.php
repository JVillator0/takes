<?php

declare(strict_types=1);

namespace App\DTOs\Customers;

use App\Support\Money\Nanodollars;

/**
 * Header stats of Customers (A09, spec 7 Definitions): how many customers, how many were active today (UTC), the
 * outstanding credits (Σ balance, the liability; held money is still the customer's) and the active holds (Σ held).
 */
final readonly class CustomerStatsDTO
{
    public function __construct(
        public int $customers,
        public int $activeToday,
        public Nanodollars $outstandingCredits,
        public Nanodollars $activeHolds,
    ) {}

    /**
     * @param  array{customers: int, active_today: int, outstanding_nanos: int|string|null, holds_nanos: int|string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['customers'],
            $data['active_today'],
            Nanodollars::fromInt($data['outstanding_nanos'] ?? 0),
            Nanodollars::fromInt($data['holds_nanos'] ?? 0),
        );
    }
}
