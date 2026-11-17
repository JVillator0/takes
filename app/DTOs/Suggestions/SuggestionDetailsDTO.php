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
 * and the customer's available credit (Generate take vs Buy credits).
 */
final readonly class SuggestionDetailsDTO
{
    public function __construct(
        public Suggestion $suggestion,
        public Take $baseTake,
        public ?UsageRecord $usage,
        public ?EstimateDTO $newTakeEstimate,
        public Nanodollars $available,
    ) {}

    /**
     * @param  array{suggestion: Suggestion, base_take: Take, usage: UsageRecord|null, new_take_estimate: EstimateDTO|null, available: Nanodollars}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['suggestion'], $data['base_take'], $data['usage'], $data['new_take_estimate'], $data['available']);
    }

    /** The validated proposal, when the suggestion has one. */
    public function proposal(): ?TakeChangeProposalDTO
    {
        /** @var array{prompt: string, size: string, quality: string, keep_references: bool, add_base_image_as_reference: bool, rationale: string}|null $stored */
        $stored = $this->suggestion->proposal;

        return $stored === null ? null : TakeChangeProposalDTO::fromArray($stored);
    }
}
