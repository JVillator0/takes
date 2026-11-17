<?php

declare(strict_types=1);

namespace App\DTOs\Suggestions;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;

/**
 * A validated "Describe a change" proposal (spec 8 "Structured output"), as
 * stored in `suggestions.proposal`. Only closed fields: the reference images
 * are re-attached by the server at apply time, so the model can never supply
 * a URL.
 */
final readonly class TakeChangeProposalDTO
{
    public function __construct(
        public string $prompt,
        public TakeSize $size,
        public TakeQuality $quality,
        public bool $keepReferences,
        public bool $addBaseImageAsReference,
        public string $rationale,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, keep_references: bool, add_base_image_as_reference: bool, rationale: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['prompt'],
            TakeSize::from($data['size']),
            TakeQuality::from($data['quality']),
            $data['keep_references'],
            $data['add_base_image_as_reference'],
            $data['rationale'],
        );
    }

    /** How many references the new take gets, given the base take's count. */
    public function referenceCount(int $baseReferenceCount): int
    {
        return ($this->keepReferences ? $baseReferenceCount : 0) + ($this->addBaseImageAsReference ? 1 : 0);
    }

    /** True when applying it would create the same take again ("AI no change", 2-01). */
    public function changesNothing(TakeChangeInputDTO $base): bool
    {
        return $this->prompt === $base->prompt
            && $this->size === $base->size
            && $this->quality === $base->quality
            && $this->referenceCount($base->referenceCount) === $base->referenceCount
            && ! $this->addBaseImageAsReference;
    }

    /**
     * @return array{prompt: string, size: string, quality: string, keep_references: bool, add_base_image_as_reference: bool, rationale: string}
     */
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'keep_references' => $this->keepReferences,
            'add_base_image_as_reference' => $this->addBaseImageAsReference,
            'rationale' => $this->rationale,
        ];
    }
}
