<?php

declare(strict_types=1);

namespace App\Queries\Suggestions;

use App\DTOs\Suggestions\SuggestionDetailsDTO;
use App\DTOs\Suggestions\TakeChangeProposalDTO;
use App\Enums\Suggestions\SuggestionStatus;
use App\Enums\Takes\ReferenceResolution;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Queries\Takes\ListReferenceImageUrlsQuery;
use App\Support\Suggestions\ProposalReferences;

/**
 * Loads one suggestion for the P07 panel. The new take's estimate is only
 * computed while the proposal can still be applied. Kept take-image
 * references are resolved once here for the references row and the apply
 * flag (#133). Reads pricing, so it is never called inside a transaction (M-16).
 */
final class GetSuggestionDetailsQuery
{
    public function __construct(
        private readonly GetSuggestedTakeEstimateQuery $estimate,
        private readonly ListReferenceImageUrlsQuery $references,
    ) {}

    public function execute(Suggestion $suggestion, Customer $customer): SuggestionDetailsDTO
    {
        $suggestion->loadMissing('usageRecord');
        $base = $suggestion->baseTake()->firstOrFail();
        $newTakeEstimate = null;
        $referenceUnavailable = false;

        /** @var array{prompt: string, size: string, quality: string, keep_references: bool, add_base_image_as_reference: bool, rationale: string}|null $stored */
        $stored = $suggestion->proposal;

        if ($stored !== null) {
            $proposal = TakeChangeProposalDTO::fromArray($stored);

            if ($suggestion->status === SuggestionStatus::Proposed) {
                $newTakeEstimate = $this->estimate->execute($proposal, $base);
            }

            $resolvedKept = $proposal->keepReferences
                ? $this->references->execute($base->customer_id, array_values($base->reference_images), ReferenceResolution::Check)
                : [];
            $referenceUnavailable = ProposalReferences::referenceUnavailable($proposal, $base, $resolvedKept);
        }

        return new SuggestionDetailsDTO(
            $suggestion,
            $base,
            $suggestion->usageRecord,
            $newTakeEstimate,
            $customer->refresh()->available(),
            $referenceUnavailable,
        );
    }
}
