<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use App\DTOs\Billing\EstimateDTO;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Support\Money\Nanodollars;
use App\Support\Money\TokenCost;
use InvalidArgumentException;

/**
 * The one implementation of the Pixelforge take estimate (spec 3-03 §4, spec 4
 * "Estimate"). The estimate endpoint (which only receives a character count)
 * and CreateTakeAction (which recomputes from the real prompt for the hold)
 * both call it. Stateless, integer arithmetic only.
 *
 * Per token kind, the minimum and maximum token counts are:
 * - input_text: est = ceil(chars / 4); min = floor(est × 85 / 100), max = ceil(est × 115 / 100)
 * - input_image: references × 765 (exact)
 * - output: the size × quality table (exact)
 * - reasoning: the quality range
 *
 * cost = ceil(Σ tokens × rate / 1_000_000) through {@see TokenCost} (rates in
 * nanodollars per 1M tokens); charge = cost with the markup (spec 3-03 §3).
 * The hold is maxCharge.
 */
final readonly class TakeEstimator
{
    /** Pixelforge token kinds, the keys of a rates snapshot (spec 3-03 §2). */
    public const array RATE_KINDS = ['input_text', 'input_image', 'output', 'reasoning'];

    private const int BPS_DENOMINATOR = 10_000;

    public function __construct(private TokenCost $tokenCost) {}

    /**
     * Estimates from the prompt itself. Characters are Unicode characters
     * (mb_strlen), as Pixelforge counts them: not bytes, not UTF-16 units.
     *
     * @param  array<string, int>  $rates  nanodollars per 1M tokens by kind
     */
    public function estimate(
        string $prompt,
        TakeSize $size,
        TakeQuality $quality,
        int $referenceImages,
        array $rates,
        int $markupBps = Nanodollars::DEFAULT_MARKUP_BPS,
    ): EstimateDTO {
        return $this->estimateForCharacters(mb_strlen($prompt, 'UTF-8'), $size, $quality, $referenceImages, $rates, $markupBps);
    }

    /**
     * Estimates from a character count (the estimate endpoint never receives the prompt text).
     *
     * @param  array<string, int>  $rates  nanodollars per 1M tokens by kind
     */
    public function estimateForCharacters(
        int $characters,
        TakeSize $size,
        TakeQuality $quality,
        int $referenceImages,
        array $rates,
        int $markupBps = Nanodollars::DEFAULT_MARKUP_BPS,
    ): EstimateDTO {
        if ($characters < 0 || $characters > PixelforgeTokenTable::MAX_PROMPT_CHARACTERS) {
            throw new InvalidArgumentException('A prompt has between 0 and '.PixelforgeTokenTable::MAX_PROMPT_CHARACTERS." characters, got [{$characters}].");
        }

        if ($referenceImages < 0 || $referenceImages > PixelforgeTokenTable::MAX_REFERENCE_IMAGES) {
            throw new InvalidArgumentException('A take has between 0 and '.PixelforgeTokenTable::MAX_REFERENCE_IMAGES." reference images, got [{$referenceImages}].");
        }

        $this->assertRates($rates);

        $estimatedTextTokens = intdiv($characters + PixelforgeTokenTable::CHARACTERS_PER_TEXT_TOKEN - 1, PixelforgeTokenTable::CHARACTERS_PER_TEXT_TOKEN);
        $imageTokens = $referenceImages * PixelforgeTokenTable::TOKENS_PER_REFERENCE_IMAGE;
        $outputTokens = PixelforgeTokenTable::outputTokens($quality, $size);
        $reasoningTokens = PixelforgeTokenTable::reasoningTokens($quality);

        $minCost = $this->tokenCost->compute([
            'input_text' => intdiv($estimatedTextTokens * (self::BPS_DENOMINATOR - PixelforgeTokenTable::TEXT_TOKEN_VARIANCE_BPS), self::BPS_DENOMINATOR),
            'input_image' => $imageTokens,
            'output' => $outputTokens,
            'reasoning' => $reasoningTokens['min'],
        ], $rates);

        $maxCost = $this->tokenCost->compute([
            'input_text' => intdiv($estimatedTextTokens * (self::BPS_DENOMINATOR + PixelforgeTokenTable::TEXT_TOKEN_VARIANCE_BPS) + self::BPS_DENOMINATOR - 1, self::BPS_DENOMINATOR),
            'input_image' => $imageTokens,
            'output' => $outputTokens,
            'reasoning' => $reasoningTokens['max'],
        ], $rates);

        return new EstimateDTO(
            $minCost,
            $maxCost,
            $minCost->withMarkup($markupBps),
            $maxCost->withMarkup($markupBps),
        );
    }

    /**
     * @param  array<string, int>  $rates
     */
    private function assertRates(array $rates): void
    {
        foreach (self::RATE_KINDS as $kind) {
            if (! array_key_exists($kind, $rates)) {
                throw new InvalidArgumentException("The rates snapshot has no [{$kind}] rate.");
            }

            if ($rates[$kind] < 0) {
                throw new InvalidArgumentException("The [{$kind}] rate cannot be negative.");
            }
        }
    }
}
