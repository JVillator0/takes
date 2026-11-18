<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Pixelforge\TokenFactsDTO;
use App\DTOs\Takes\EstimateTakeDTO;
use App\DTOs\Takes\TakeQuoteDTO;
use App\Queries\Billing\GetTakePricingQuery;
use App\Support\Pricing\TakeEstimator;

/**
 * The price range of a take before it runs (spec 4 "Estimate"): the one
 * TakeEstimator with fresh GET /v1/pricing facts (cached 1 h, config
 * fallback). The estimate endpoint and CreateTakeAction both use it, so the
 * range on the button and the hold are computed the same way; the action
 * recomputes it from the real prompt for the hold.
 */
final class EstimateTakeQuery
{
    public function __construct(
        private readonly GetTakePricingQuery $pricing,
        private readonly TakeEstimator $estimator,
    ) {}

    /**
     * @param  bool  $cachedPricing  never call the provider for the pricing (a public page): cache, else config fallback
     */
    public function execute(EstimateTakeDTO $input, bool $cachedPricing = false): TakeQuoteDTO
    {
        $pricing = $this->pricing->execute($cachedPricing);
        $rates = $pricing->rates->toNanosPerMillion();

        return new TakeQuoteDTO(
            $this->estimator->estimateForCharacters(
                $input->characters,
                $input->size,
                $input->quality,
                $input->referenceImages,
                $rates,
                TokenFactsDTO::fromPricing($pricing),
            ),
            new RatesSnapshotDTO($rates),
        );
    }
}
