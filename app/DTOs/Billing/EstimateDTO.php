<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\Nanodollars;

/**
 * What a provider call may cost and charge (spec 3-03 §4). Every estimator
 * (Pixelforge spec 4, Anthropic spec 8) returns one. The hold is always
 * `maxCharge` (D-024).
 */
final readonly class EstimateDTO
{
    public function __construct(
        public Nanodollars $minCost,
        public Nanodollars $maxCost,
        public Nanodollars $minCharge,
        public Nanodollars $maxCharge,
    ) {}
}
