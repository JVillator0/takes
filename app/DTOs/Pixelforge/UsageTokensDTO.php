<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Support\Pixelforge\Payload;

/**
 * Token usage of a succeeded generation (null on the snapshot until then).
 *
 * @phpstan-type UsageTokensPayload array{
 *     input_text_tokens: int,
 *     input_image_tokens: int,
 *     output_tokens: int,
 *     reasoning_tokens: int,
 *     total_tokens: int,
 * }
 */
final readonly class UsageTokensDTO
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $totalTokens,
    ) {}

    /**
     * @param  array<mixed>  $data  a {@see UsageTokensPayload}
     */
    public static function fromArray(array $data): self
    {
        return new self(
            inputTextTokens: Payload::count($data, 'input_text_tokens'),
            inputImageTokens: Payload::count($data, 'input_image_tokens'),
            outputTokens: Payload::count($data, 'output_tokens'),
            reasoningTokens: Payload::count($data, 'reasoning_tokens'),
            totalTokens: Payload::count($data, 'total_tokens'),
        );
    }

    /**
     * Units by kind for the usage record (spec 3-03 §2).
     *
     * @return array{input_text: int, input_image: int, output: int, reasoning: int}
     */
    public function toUnits(): array
    {
        return [
            'input_text' => $this->inputTextTokens,
            'input_image' => $this->inputImageTokens,
            'output' => $this->outputTokens,
            'reasoning' => $this->reasoningTokens,
        ];
    }
}
