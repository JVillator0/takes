<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Support\Money\Nanodollars;
use App\Support\Pixelforge\Payload;
use InvalidArgumentException;

/**
 * Per-token-kind rates in nanodollars per 1M tokens (spec 3-03 §2). Parsed
 * from the generation's `rates` or from GET /v1/pricing, both decimal USD
 * strings with the same keys: "12.50" ⇒ 12_500_000_000.
 *
 * @phpstan-type RatesPayload array{
 *     input_text_tokens: string,
 *     input_image_tokens: string,
 *     output_tokens: string,
 *     reasoning_tokens: string,
 * }
 */
final readonly class RatesDTO
{
    public function __construct(
        public Nanodollars $inputText,
        public Nanodollars $inputImage,
        public Nanodollars $output,
        public Nanodollars $reasoning,
    ) {}

    /**
     * @param  array<mixed>  $data  a {@see RatesPayload}
     */
    public static function fromArray(array $data): self
    {
        return new self(
            inputText: self::rate($data, 'input_text_tokens'),
            inputImage: self::rate($data, 'input_image_tokens'),
            output: self::rate($data, 'output_tokens'),
            reasoning: self::rate($data, 'reasoning_tokens'),
        );
    }

    /**
     * The usage-record rates snapshot (spec 3-03 §2 kinds), nanodollars per 1M tokens.
     *
     * @return array{input_text: int, input_image: int, output: int, reasoning: int}
     */
    public function toNanosPerMillion(): array
    {
        return [
            'input_text' => $this->inputText->toInt(),
            'input_image' => $this->inputImage->toInt(),
            'output' => $this->output->toInt(),
            'reasoning' => $this->reasoning->toInt(),
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function rate(array $data, string $key): Nanodollars
    {
        // Strings only: a JSON number would already have been through a float.
        $value = Payload::string($data, $key);

        try {
            return Nanodollars::perMillionFromDecimalString($value);
        } catch (InvalidArgumentException) {
            throw Payload::invalid($key, 'a non-negative USD decimal string');
        }
    }
}
