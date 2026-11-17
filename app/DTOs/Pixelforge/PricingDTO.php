<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Support\Pixelforge\Payload;

/**
 * GET /v1/pricing: the current rates and the token tables the estimator
 * needs (spec 3-03 §4). The same shape is the config fallback.
 *
 * `text_token_variance` arrives as a JSON number (0.15); it is kept as
 * integer basis points (1500) via its decimal string, never as a float.
 *
 * @phpstan-import-type RatesPayload from RatesDTO
 *
 * @phpstan-type PricingPayload array{
 *     object?: 'pricing',
 *     currency?: 'usd',
 *     unit?: 'per_1m_tokens',
 *     rates: RatesPayload,
 *     tokens: array{
 *         characters_per_text_token: int,
 *         text_token_variance: string,
 *         per_reference_image: int,
 *         output: array<'low'|'medium'|'high', array<'1024x1024'|'1536x1024'|'1024x1536', int>>,
 *         reasoning: array<'low'|'medium'|'high', array{min: int, max: int}>,
 *     },
 * }
 */
final readonly class PricingDTO
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality ⇒ size ⇒ tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality ⇒ range
     */
    public function __construct(
        public RatesDTO $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBps,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<mixed>  $data  a {@see PricingPayload}
     */
    public static function fromArray(array $data): self
    {
        $tokens = Payload::array($data, 'tokens');
        $outputTable = Payload::array($tokens, 'output');
        $reasoningTable = Payload::array($tokens, 'reasoning');

        $output = [];
        $reasoning = [];

        foreach (TakeQuality::cases() as $quality) {
            $bySize = Payload::array($outputTable, $quality->value);

            foreach (TakeSize::cases() as $size) {
                $output[$quality->value][$size->value] = Payload::count($bySize, $size->value);
            }

            $range = Payload::array($reasoningTable, $quality->value);
            $min = Payload::count($range, 'min');
            $max = Payload::count($range, 'max');

            if ($min > $max) {
                throw Payload::invalid("reasoning.{$quality->value}", 'a range with min <= max');
            }

            $reasoning[$quality->value] = ['min' => $min, 'max' => $max];
        }

        $charactersPerToken = Payload::count($tokens, 'characters_per_text_token');

        if ($charactersPerToken === 0) {
            throw Payload::invalid('characters_per_text_token', 'a positive integer');
        }

        return new self(
            rates: RatesDTO::fromArray(Payload::array($data, 'rates')),
            charactersPerTextToken: $charactersPerToken,
            textTokenVarianceBps: self::basisPoints($tokens['text_token_variance'] ?? null),
            tokensPerReferenceImage: Payload::count($tokens, 'per_reference_image'),
            outputTokens: $output,
            reasoningTokens: $reasoning,
        );
    }

    public function outputTokensFor(TakeQuality $quality, TakeSize $size): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningRangeFor(TakeQuality $quality): array
    {
        return $this->reasoningTokens[$quality->value];
    }

    /**
     * "0.15" (or the decoded JSON number, through its shortest string form)
     * ⇒ 1500. At most 4 decimals, so the result is always an exact integer.
     */
    private static function basisPoints(mixed $value): int
    {
        $string = is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';

        if (preg_match('/^(\d)(?:\.(\d{1,4}))?$/', $string, $matches) !== 1) {
            throw Payload::invalid('text_token_variance', 'a ratio with at most 4 decimals');
        }

        return (int) $matches[1] * 10_000 + (int) str_pad($matches[2] ?? '', 4, '0');
    }
}
