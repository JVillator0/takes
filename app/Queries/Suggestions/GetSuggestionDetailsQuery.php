<?php

declare(strict_types=1);

namespace App\Queries\Suggestions;

use App\DTOs\Suggestions\SuggestionDetailsDTO;
use App\DTOs\Suggestions\TakeChangeProposalDTO;
use App\Enums\Suggestions\SuggestionStatus;
use App\Models\Customer;
use App\Models\Suggestion;

/**
 * Loads one suggestion for the P07 panel. The new take's estimate is only
 * computed while the proposal can still be applied. Reads pricing, so it is
 * never called inside a transaction (M-16).
 */
final class GetSuggestionDetailsQuery
{
    public function __construct(private readonly GetSuggestedTakeEstimateQuery $estimate) {}

    public function execute(Suggestion $suggestion, Customer $customer): SuggestionDetailsDTO
    {
        $suggestion->loadMissing('usageRecord');
        $base = $suggestion->baseTake()->firstOrFail();
        $newTakeEstimate = null;

        /** @var array{prompt: string, size: string, quality: string, keep_references: bool, add_base_image_as_reference: bool, rationale: string}|null $stored */
        $stored = $suggestion->proposal;

        if ($suggestion->status === SuggestionStatus::Proposed && $stored !== null) {
            $newTakeEstimate = $this->estimate->execute(TakeChangeProposalDTO::fromArray($stored), $base);
        }

        return new SuggestionDetailsDTO(
            $suggestion,
            $base,
            $suggestion->usageRecord,
            $newTakeEstimate,
            $customer->refresh()->available(),
        );
    }
}
