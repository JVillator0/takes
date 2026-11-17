<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\BasisPoints;
use App\Support\Money\Nanodollars;

/**
 * One row of Billing › Reports (A03) or its totals, from settled usage records (spec 7 Definitions): spend, cost
 * and the per-provider costs as exact nanodollars, profit = spend − cost, margin = profit × 10,000 ÷ spend in
 * integer bps (null when spend is 0, shown "-"), plus the take counts of the same day or customer.
 */
final readonly class BillingReportRowDTO
{
    /** 25% markup on cost is 20.0% of spend (spec 7 Definitions): any other margin is an invariant alert. */
    public const int MARGIN_INVARIANT_BPS = 2000;

    public function __construct(
        public string $key,
        public string $label,
        public Nanodollars $spend,
        public Nanodollars $cost,
        public Nanodollars $pixelforgeCost,
        public Nanodollars $anthropicCost,
        public int $generationsCharged,
        public int $suggestions,
        public int $takes,
        public int $failedTakes,
    ) {}

    /**
     * @param  array{key: string, label: string, spend_nanos: int|string|null, cost_nanos: int|string|null, pixelforge_cost_nanos: int|string|null, anthropic_cost_nanos: int|string|null, generations_charged: int|string|null, suggestions: int|string|null, takes: int, failed_takes: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['key'],
            $data['label'],
            Nanodollars::fromInt($data['spend_nanos'] ?? 0),
            Nanodollars::fromInt($data['cost_nanos'] ?? 0),
            Nanodollars::fromInt($data['pixelforge_cost_nanos'] ?? 0),
            Nanodollars::fromInt($data['anthropic_cost_nanos'] ?? 0),
            (int) ($data['generations_charged'] ?? 0),
            (int) ($data['suggestions'] ?? 0),
            $data['takes'],
            $data['failed_takes'],
        );
    }

    public function profit(): Nanodollars
    {
        return $this->spend->minus($this->cost);
    }

    /** Integer basis points of profit over spend; null when nothing was spent. */
    public function marginBps(): ?int
    {
        return BasisPoints::ratio($this->profit()->toInt(), $this->spend->toInt());
    }

    /** Spend with a margin other than 20.0% (an empty row, with no margin, never breaks it). */
    public function breaksMarginInvariant(): bool
    {
        $margin = $this->marginBps();

        return $margin !== null && $margin !== self::MARGIN_INVARIANT_BPS;
    }

    /** This row's share of the total spend, in integer basis points. */
    public function shareOfSpendBps(Nanodollars $totalSpend): ?int
    {
        return BasisPoints::ratio($this->spend->toInt(), $totalSpend->toInt());
    }
}
