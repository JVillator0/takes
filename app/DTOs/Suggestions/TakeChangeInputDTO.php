<?php

declare(strict_types=1);

namespace App\DTOs\Suggestions;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Models\Take;

/**
 * What the "Describe a change" model sees (spec 8 "Agent"): the base take's
 * parameters and the customer's instruction. Reference URLs are never sent,
 * only their count, so the model cannot copy or invent one.
 */
final readonly class TakeChangeInputDTO
{
    public function __construct(
        public string $prompt,
        public TakeSize $size,
        public TakeQuality $quality,
        public int $referenceCount,
        public string $instruction,
    ) {}

    public static function forTake(Take $take, string $instruction): self
    {
        return new self(
            $take->prompt,
            $take->size,
            $take->quality,
            count($take->reference_images),
            $instruction,
        );
    }

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_count: int, instruction: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['prompt'],
            TakeSize::from($data['size']),
            TakeQuality::from($data['quality']),
            $data['reference_count'],
            $data['instruction'],
        );
    }

    /**
     * The user message. The instruction is data inside a fixed JSON envelope,
     * never concatenated into the instructions (prompt-injection stance).
     */
    public function toMessage(): string
    {
        return (string) json_encode([
            'base_take' => [
                'prompt' => $this->prompt,
                'size' => $this->size->value,
                'quality' => $this->quality->value,
                'reference_count' => $this->referenceCount,
            ],
            'instruction' => $this->instruction,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
