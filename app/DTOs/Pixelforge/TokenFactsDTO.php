<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Support\Pricing\PixelforgeTokenTable;
use InvalidArgumentException;

/**
 * The token facts the take estimator needs (spec 4 "Estimate"): the `tokens`
 * block of GET /v1/pricing, parsed to integers at this boundary. The variance
 * arrives as a JSON number (0.15) or a string ("0.15") and becomes basis
 * points (1500) through its decimal string, never through float arithmetic.
 * {@see self::defaults()} is the documented table, used when /v1/pricing is
 * unavailable.
 */
final readonly class TokenFactsDTO
{
    private const int BPS_DENOMINATOR = 10_000;

    /**
     * @param  array<value-of<TakeQuality>, array<value-of<TakeSize>, int>>  $outputTokens  quality ⇒ size ⇒ tokens
     * @param  array<value-of<TakeQuality>, array{min: int, max: int}>  $reasoningTokens  quality ⇒ range
     */
    public function __construct(
        public int $charactersPerTextToken,
        public int $textTokenVarianceBps,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {
        if ($charactersPerTextToken < 1) {
            throw new InvalidArgumentException('characters_per_text_token must be a positive integer.');
        }

        if ($textTokenVarianceBps < 0 || $textTokenVarianceBps >= self::BPS_DENOMINATOR) {
            throw new InvalidArgumentException('text_token_variance must be at least 0 and below 1.');
        }

        if ($tokensPerReferenceImage < 0 || $tokensPerReferenceImage > intdiv(PHP_INT_MAX, PixelforgeTokenTable::MAX_REFERENCE_IMAGES)) {
            throw new InvalidArgumentException('per_reference_image must be a non-negative integer that cannot overflow.');
        }

        foreach (TakeQuality::cases() as $quality) {
            foreach (TakeSize::cases() as $size) {
                if (($outputTokens[$quality->value][$size->value] ?? -1) < 0) {
                    throw new InvalidArgumentException("output.{$quality->value}.{$size->value} must be a non-negative integer.");
                }
            }

            $range = $reasoningTokens[$quality->value] ?? null;

            if ($range === null || $range['min'] < 0 || $range['min'] > $range['max']) {
                throw new InvalidArgumentException("reasoning.{$quality->value} must be a range with 0 <= min <= max.");
            }
        }
    }

    /** The token facts of a parsed GET /v1/pricing (or its config fallback). */
    public static function fromPricing(PricingDTO $pricing): self
    {
        $output = [];
        $reasoning = [];

        foreach (TakeQuality::cases() as $quality) {
            foreach (TakeSize::cases() as $size) {
                $output[$quality->value][$size->value] = $pricing->outputTokensFor($quality, $size);
            }

            $reasoning[$quality->value] = $pricing->reasoningRangeFor($quality);
        }

        return new self(
            $pricing->charactersPerTextToken,
            $pricing->textTokenVarianceBps,
            $pricing->tokensPerReferenceImage,
            $output,
            $reasoning,
        );
    }

    /** The documented token table (PixelforgeTokenTable), the fallback when /v1/pricing is unavailable. */
    public static function defaults(): self
    {
        return new self(
            PixelforgeTokenTable::CHARACTERS_PER_TEXT_TOKEN,
            PixelforgeTokenTable::TEXT_TOKEN_VARIANCE_BPS,
            PixelforgeTokenTable::TOKENS_PER_REFERENCE_IMAGE,
            PixelforgeTokenTable::OUTPUT_TOKENS,
            PixelforgeTokenTable::REASONING_TOKENS,
        );
    }

    /**
     * Parses a GET /v1/pricing response (decoded JSON). Only `tokens` is read;
     * the rates are parsed separately into the rates snapshot.
     *
     * @param  array<array-key, mixed>  $pricing
     */
    public static function fromPricingArray(array $pricing): self
    {
        $tokens = self::arrayAt($pricing, 'tokens', 'tokens');
        $outputTable = self::arrayAt($tokens, 'output', 'tokens.output');
        $reasoningTable = self::arrayAt($tokens, 'reasoning', 'tokens.reasoning');

        $output = [];
        $reasoning = [];

        foreach (TakeQuality::cases() as $quality) {
            $bySize = self::arrayAt($outputTable, $quality->value, "tokens.output.{$quality->value}");

            foreach (TakeSize::cases() as $size) {
                $output[$quality->value][$size->value] = self::intAt($bySize, $size->value, "tokens.output.{$quality->value}.{$size->value}");
            }

            $range = self::arrayAt($reasoningTable, $quality->value, "tokens.reasoning.{$quality->value}");
            $reasoning[$quality->value] = [
                'min' => self::intAt($range, 'min', "tokens.reasoning.{$quality->value}.min"),
                'max' => self::intAt($range, 'max', "tokens.reasoning.{$quality->value}.max"),
            ];
        }

        return new self(
            self::intAt($tokens, 'characters_per_text_token', 'tokens.characters_per_text_token'),
            self::basisPoints($tokens['text_token_variance'] ?? null),
            self::intAt($tokens, 'per_reference_image', 'tokens.per_reference_image'),
            $output,
            $reasoning,
        );
    }

    public function outputTokensFor(TakeQuality $quality, TakeSize $size): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningTokensFor(TakeQuality $quality): array
    {
        return $this->reasoningTokens[$quality->value];
    }

    /**
     * "0.15" or the decoded JSON number 0.15 ⇒ 1500, through the value's
     * decimal string. At most 4 decimals, so the result is an exact integer.
     */
    private static function basisPoints(mixed $value): int
    {
        $decimal = is_numeric($value) ? (string) $value : '';

        if (preg_match('/^0(?:\.(\d{1,4}))?$/', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException('tokens.text_token_variance must be a ratio below 1 with at most 4 decimals.');
        }

        return (int) str_pad($matches[1] ?? '', 4, '0');
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function arrayAt(array $data, string $key, string $path): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new InvalidArgumentException("{$path} must be an object.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function intAt(array $data, string $key, string $path): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException("{$path} must be an integer.");
        }

        return $value;
    }
}
