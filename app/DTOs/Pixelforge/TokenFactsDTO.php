<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Support\Pricing\PixelforgeTokenTable;
use InvalidArgumentException;

/**
 * The token facts the take estimator needs (spec 4 "Estimate"): the `tokens`
 * block of GET /v1/pricing as integers. {@see PricingDTO} is the one parser
 * of that block (the variance becomes basis points there, independent of the
 * `precision` ini); this DTO adds the bounds that keep every estimate within
 * integer arithmetic. PricingDTO::fromArray() checks a payload against them,
 * so an absurd /v1/pricing is unreadable and the client serves the config
 * fallback, instead of failing later with a TypeError or an overflow (#107).
 * {@see self::defaults()} is the documented table.
 */
final readonly class TokenFactsDTO
{
    private const int BPS_DENOMINATOR = 10_000;

    /**
     * Above this, every prompt (at most MAX_PROMPT_CHARACTERS) is one text
     * token anyway; the bound keeps `characters + cpt − 1` far from PHP_INT_MAX.
     */
    public const int MAX_CHARACTERS_PER_TEXT_TOKEN = PixelforgeTokenTable::MAX_PROMPT_CHARACTERS;

    /**
     * Sanity bound for a token count per kind (per reference image, output,
     * reasoning): 125× the largest documented value (8,000 reasoning tokens).
     * An estimate then stays below ~6M tokens, so Σ tokens × rate cannot
     * overflow for any rate under ~$1,500 per 1M tokens (documented: $40).
     */
    public const int MAX_TOKENS_PER_KIND = 1_000_000;

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
        if ($charactersPerTextToken < 1 || $charactersPerTextToken > self::MAX_CHARACTERS_PER_TEXT_TOKEN) {
            throw new InvalidArgumentException('characters_per_text_token must be between 1 and '.self::MAX_CHARACTERS_PER_TEXT_TOKEN.'.');
        }

        if ($textTokenVarianceBps < 0 || $textTokenVarianceBps >= self::BPS_DENOMINATOR) {
            throw new InvalidArgumentException('text_token_variance must be at least 0 and below 1.');
        }

        if (! self::isTokenCount($tokensPerReferenceImage)) {
            throw new InvalidArgumentException('per_reference_image must be between 0 and '.self::MAX_TOKENS_PER_KIND.'.');
        }

        foreach (TakeQuality::cases() as $quality) {
            foreach (TakeSize::cases() as $size) {
                if (! self::isTokenCount($outputTokens[$quality->value][$size->value] ?? -1)) {
                    throw new InvalidArgumentException("output.{$quality->value}.{$size->value} must be between 0 and ".self::MAX_TOKENS_PER_KIND.'.');
                }
            }

            $range = $reasoningTokens[$quality->value] ?? null;

            if ($range === null || $range['min'] < 0 || $range['min'] > $range['max'] || ! self::isTokenCount($range['max'])) {
                throw new InvalidArgumentException("reasoning.{$quality->value} must be a range with 0 <= min <= max <= ".self::MAX_TOKENS_PER_KIND.'.');
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

    private static function isTokenCount(int $count): bool
    {
        return $count >= 0 && $count <= self::MAX_TOKENS_PER_KIND;
    }
}
