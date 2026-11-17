<?php

declare(strict_types=1);

namespace App\Queries\Suggestions;

use App\Ai\Agents\TakeChangeAgent;
use App\DTOs\Billing\EstimateDTO;
use App\DTOs\Suggestions\TakeChangeInputDTO;
use App\Enums\Billing\Provider;
use App\Models\Take;
use App\Support\Pricing\AiPricing;
use App\Support\Pricing\SuggestionEstimator;

/**
 * The most a suggestion on this take can hold, shown before the customer
 * types (P07 step 1, "price before action", F-15): the per-request formula
 * over the worst-case instruction (the maximum length, every character at
 * its largest JSON encoding). The real request's hold is never above it.
 */
final class GetSuggestionQuoteQuery
{
    public const int MAX_INSTRUCTION_CHARACTERS = 500;

    public function __construct(
        private readonly SuggestionEstimator $estimator,
        private readonly AiPricing $pricing,
    ) {}

    public function execute(Take $base): EstimateDTO
    {
        $bytes = strlen((new TakeChangeAgent)->fixedRequestText().TakeChangeInputDTO::forTake($base, '')->toMessage())
            + self::MAX_INSTRUCTION_CHARACTERS * SuggestionEstimator::MAX_BYTES_PER_INSTRUCTION_CHARACTER;

        return $this->estimator->estimateForBytes($bytes, $this->pricing->rates(Provider::Anthropic, TakeChangeAgent::MODEL));
    }
}
