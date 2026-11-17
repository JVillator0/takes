<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\Nanodollars;

/**
 * A price estimate for one provider call (spec 3-03 §4): what the provider may
 * cost us and what the customer may be charged. The hold is always `maxCharge`.
 * Produced by the estimators (spec 3-03 `TakeEstimator`, spec 8); read by
 * `TakePolicy::create` and `SuggestionPolicy::create`.
 */
final readonly class EstimateDTO
{
    public function __construct(
        public Nanodollars $minCost,
        public Nanodollars $maxCost,
        public Nanodollars $minCharge,
        public Nanodollars $maxCharge,
    ) {}

    /**
     * @param  array{min_cost_nanos: int, max_cost_nanos: int, min_charge_nanos: int, max_charge_nanos: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            minCost: Nanodollars::fromInt($data['min_cost_nanos']),
            maxCost: Nanodollars::fromInt($data['max_cost_nanos']),
            minCharge: Nanodollars::fromInt($data['min_charge_nanos']),
            maxCharge: Nanodollars::fromInt($data['max_charge_nanos']),
        );
    }
}
