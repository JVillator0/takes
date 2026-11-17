<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;

/**
 * Pixelforge token facts: how many tokens a generation uses, by kind. These
 * are provider facts, not prices (prices are the `rates` snapshot, spec 3-03
 * §2). Copied verbatim from https://pixelforge.promptedit.dev/docs ("Pricing
 * and token usage", "How tokens are counted" and the GET /v1/pricing example),
 * retrieved 2026-11-17. A test checks them against a verbatim fixture.
 */
final class PixelforgeTokenTable
{
    public const string SOURCE_URL = 'https://pixelforge.promptedit.dev/docs';

    public const string RETRIEVED_ON = '2026-11-17';

    /** "At most 4,000 characters" (Unicode characters, the same count as the text tokens). */
    public const int MAX_PROMPT_CHARACTERS = 4000;

    /** "at most 4" reference images. */
    public const int MAX_REFERENCE_IMAGES = 4;

    /** "about one token per 4 characters of prompt", estimated as ceil(characters / 4). */
    public const int CHARACTERS_PER_TEXT_TOKEN = 4;

    /** "within 15% of that estimate in either direction", as basis points. */
    public const int TEXT_TOKEN_VARIANCE_BPS = 1500;

    /** "exactly 765 per reference image". */
    public const int TOKENS_PER_REFERENCE_IMAGE = 765;

    /**
     * Output tokens, exact, by quality and size.
     *
     * @var array<value-of<TakeQuality>, array<value-of<TakeSize>, int>>
     */
    public const array OUTPUT_TOKENS = [
        'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
        'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
        'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
    ];

    /**
     * Reasoning tokens: only known once the generation completes, always within this range.
     *
     * @var array<value-of<TakeQuality>, array{min: int, max: int}>
     */
    public const array REASONING_TOKENS = [
        'low' => ['min' => 0, 'max' => 500],
        'medium' => ['min' => 200, 'max' => 2000],
        'high' => ['min' => 1000, 'max' => 8000],
    ];

    public static function outputTokens(TakeQuality $quality, TakeSize $size): int
    {
        return self::OUTPUT_TOKENS[$quality->value][$size->value];
    }

    /**
     * @return array{min: int, max: int}
     */
    public static function reasoningTokens(TakeQuality $quality): array
    {
        return self::REASONING_TOKENS[$quality->value];
    }
}
