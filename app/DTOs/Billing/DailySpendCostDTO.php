<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\Nanodollars;

/**
 * One UTC billing day of the dashboard's "Spend, cost and profit" chart: settled spend per provider (keyed by
 * the Provider value, every provider present), the day's total spend and provider cost, and profit. A day with
 * no settled usage carries zeros.
 */
final readonly class DailySpendCostDTO
{
    /**
     * @param  array<string, Nanodollars>  $spendByProvider
     */
    public function __construct(
        public string $date,
        public array $spendByProvider,
        public Nanodollars $spend,
        public Nanodollars $cost,
        public Nanodollars $profit,
    ) {}

    /**
     * @param  array{date: string, spend_by_provider: array<string, Nanodollars>, spend: Nanodollars, cost: Nanodollars, profit: Nanodollars}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['date'], $data['spend_by_provider'], $data['spend'], $data['cost'], $data['profit']);
    }
}
