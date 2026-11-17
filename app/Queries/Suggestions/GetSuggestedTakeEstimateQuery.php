<?php

declare(strict_types=1);

namespace App\Queries\Suggestions;

use App\DTOs\Billing\EstimateDTO;
use App\DTOs\Pixelforge\TokenFactsDTO;
use App\DTOs\Suggestions\TakeChangeProposalDTO;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Models\Take;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Pricing\TakeEstimator;
use App\Support\Suggestions\ProposalReferences;

/**
 * The new take's price range shown on a proposal (P07 "New take: $x – $y",
 * the max on *Generate take*). Same TakeEstimator and GET /v1/pricing facts
 * (cached, config fallback) as spec 4's estimate; the hold itself is
 * recomputed by take creation at apply time. May call Pixelforge's public
 * pricing endpoint: never call it inside a transaction (M-16).
 */
final class GetSuggestedTakeEstimateQuery
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly TakeEstimator $estimator,
    ) {}

    public function execute(TakeChangeProposalDTO $proposal, Take $base): EstimateDTO
    {
        $pricing = $this->client->getPricing();
        $outputTokens = [];
        $reasoningTokens = [];

        foreach (TakeQuality::cases() as $quality) {
            foreach (TakeSize::cases() as $size) {
                $outputTokens[$quality->value][$size->value] = $pricing->outputTokensFor($quality, $size);
            }

            $reasoningTokens[$quality->value] = $pricing->reasoningRangeFor($quality);
        }

        return $this->estimator->estimate(
            $proposal->prompt,
            $proposal->size,
            $proposal->quality,
            ProposalReferences::count($proposal, $base),
            $pricing->rates->toNanosPerMillion(),
            new TokenFactsDTO(
                $pricing->charactersPerTextToken,
                $pricing->textTokenVarianceBps,
                $pricing->tokensPerReferenceImage,
                $outputTokens,
                $reasoningTokens,
            ),
        );
    }
}
