<?php

declare(strict_types=1);

namespace App\DTOs\Suggestions;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Enums\Takes\TakeSource;

/**
 * The take an applied suggestion creates (spec 8 step 4): source `ai_change`,
 * lineage to the base take, the suggestion id, and an idempotency key derived
 * from the suggestion id, so a second apply replays the same take (D-033).
 * Field for field what spec 4's CreateTakeDTO carries.
 */
final readonly class SuggestedTakeDTO
{
    public TakeSource $source;

    /**
     * @param  list<string>  $referenceImages  re-attached by the server, never from the model
     */
    public function __construct(
        public string $prompt,
        public TakeSize $size,
        public TakeQuality $quality,
        public array $referenceImages,
        public string $idempotencyKey,
        public string $parentTakeId,
        public string $suggestionId,
    ) {
        $this->source = TakeSource::AiChange;
    }

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>, idempotency_key: string, parent_take_id: string, suggestion_id: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['prompt'],
            TakeSize::from($data['size']),
            TakeQuality::from($data['quality']),
            $data['reference_images'],
            $data['idempotency_key'],
            $data['parent_take_id'],
            $data['suggestion_id'],
        );
    }
}
