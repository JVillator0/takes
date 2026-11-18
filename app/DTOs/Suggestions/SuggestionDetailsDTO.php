<?php

declare(strict_types=1);

namespace App\DTOs\Suggestions;

use App\DTOs\Billing\EstimateDTO;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;

/**
 * Everything the P07 panel shows for one suggestion: the suggestion, its base
 * take, its usage (hold or charge), the new take's estimate when proposed,
 * the customer's available credit (Generate take vs Buy credits), and whether
 * a reference the new take needs no longer resolves (#133), computed once.
 */
final readonly class SuggestionDetailsDTO
{
    public function __construct(
        public Suggestion $suggestion,
        public Take $baseTake,
        public ?UsageRecord $usage,
        public ?EstimateDTO $newTakeEstimate,
        public Nanodollars $available,
        public bool $referenceUnavailable = false,
    ) {}

    /**
     * @param  array{suggestion: Suggestion, base_take: Take, usage: UsageRecord|null, new_take_estimate: EstimateDTO|null, available: Nanodollars, reference_unavailable?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['suggestion'], $data['base_take'], $data['usage'], $data['new_take_estimate'], $data['available'], $data['reference_unavailable'] ?? false);
    }

    /** The validated proposal, when the suggestion has one. */
    public function proposal(): ?TakeChangeProposalDTO
    {
        /** @var array{prompt: string, size: string, quality: string, keep_references: bool, add_base_image_as_reference: bool, rationale: string}|null $stored */
        $stored = $this->suggestion->proposal;

        return $stored === null ? null : TakeChangeProposalDTO::fromArray($stored);
    }
}
