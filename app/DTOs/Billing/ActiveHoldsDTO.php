<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\Nanodollars;

/**
 * Active holds (spec 7 Definitions): Σ `customers.held_nanos` and the number of open usage records behind it
 * (holds are state, not ledger rows, D-024).
 */
final readonly class ActiveHoldsDTO
{
    public function __construct(
        public Nanodollars $held,
        public int $openUsageRecords,
    ) {}

    /**
     * @param  array{held: Nanodollars, open_usage_records: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['held'], $data['open_usage_records']);
    }
}
