<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\Nanodollars;

/**
 * Spend, provider cost, profit and margin of settled usage over a range of UTC billing days (spec 7 Definitions):
 * margin is `profit × 10,000 ÷ spend` in integer basis points, null when nothing was spent (shown `-`).
 */
final readonly class MoneyTotalsDTO
{
    public function __construct(
        public Nanodollars $spend,
        public Nanodollars $cost,
        public Nanodollars $profit,
        public ?int $marginBps,
    ) {}

    /**
     * @param  array{spend: Nanodollars, cost: Nanodollars, profit: Nanodollars, margin_bps: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['spend'], $data['cost'], $data['profit'], $data['margin_bps']);
    }
}
