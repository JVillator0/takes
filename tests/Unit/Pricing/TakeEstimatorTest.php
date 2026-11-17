<?php

declare(strict_types=1);

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;
use App\Support\Pricing\PixelforgeTokenTable;
use App\Support\Pricing\TakeEstimator;

/*
| Oracles come from https://pixelforge.promptedit.dev/docs (retrieved
| 2026-11-17, fixture tests/Fixtures/Pricing/pixelforge-docs-pricing.json)
| and from .claude/specs/2-product-design/demo-dataset.md. Expected values
| are written out literally, never recomputed with the code under test.
*/

beforeEach(function (): void {
    $this->estimator = new TakeEstimator;

    // Docs rates ($5.00 / $10.00 / $40.00 / $12.50 per 1M) in nanodollars per 1M tokens.
    $this->rates = [
        'input_text' => 5_000_000_000,
        'input_image' => 10_000_000_000,
        'output' => 40_000_000_000,
        'reasoning' => 12_500_000_000,
    ];

    /** Rates with every kind at zero except one, to isolate a kind's token count. */
    $this->onlyRate = fn (string $kind, int $rate): array => array_merge(
        ['input_text' => 0, 'input_image' => 0, 'output' => 0, 'reasoning' => 0],
        [$kind => $rate],
    );
});

describe('docs worked example', function (): void {
    it('reproduces the maximum cost exactly: 400 chars, high, 1536x1024, 2 references', function (): void {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Pricing/pixelforge-docs-pricing.json'), true, flags: JSON_THROW_ON_ERROR);
        $example = $fixture['worked_example'];

        $estimate = $this->estimator->estimate(
            str_repeat('a', $example['prompt_characters']),
            TakeSize::from($example['size']),
            TakeQuality::from($example['quality']),
            $example['reference_images'],
            $this->rates,
        );

        expect($estimate->maxCost->toInt())->toBe(373_875_000)
            ->and($estimate->maxCost->toInt())->toBe(Nanodollars::fromDecimalString($example['maximum_total'])->toInt())
            ->and($estimate->maxCharge->toInt())->toBe(467_343_750)
            // Minimum: 85 text + 1,530 image + 6,450 output + 1,000 reasoning tokens.
            ->and($estimate->minCost->toInt())->toBe(85 * 5_000 + 1_530 * 10_000 + 6_450 * 40_000 + 1_000 * 12_500)
            ->and($estimate->minCharge->toInt())->toBe(357_781_250);
    });
});

describe('token tables', function (): void {
    it('matches the GET /v1/pricing token tables in the docs verbatim', function (): void {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Pricing/pixelforge-docs-pricing.json'), true, flags: JSON_THROW_ON_ERROR);
        $tokens = $fixture['response']['tokens'];

        expect(PixelforgeTokenTable::OUTPUT_TOKENS)->toBe($tokens['output'])
            ->and(PixelforgeTokenTable::REASONING_TOKENS)->toBe($tokens['reasoning'])
            ->and(PixelforgeTokenTable::CHARACTERS_PER_TEXT_TOKEN)->toBe($tokens['characters_per_text_token'])
            ->and(PixelforgeTokenTable::TOKENS_PER_REFERENCE_IMAGE)->toBe($tokens['per_reference_image'])
            ->and((string) PixelforgeTokenTable::TEXT_TOKEN_VARIANCE_BPS)->toBe('1500')
            ->and((string) $tokens['text_token_variance'])->toBe('0.15');
    });

    it('charges every output table entry exactly', function (TakeQuality $quality, TakeSize $size, int $outputTokens): void {
        $estimate = $this->estimator->estimateForCharacters(0, $size, $quality, 0, ($this->onlyRate)('output', 40_000_000_000));

        expect($estimate->minCost->toInt())->toBe($outputTokens * 40_000)
            ->and($estimate->maxCost->toInt())->toBe($outputTokens * 40_000);
    })->with([
        'low 1024x1024' => [TakeQuality::Low, TakeSize::Square, 280],
        'low 1536x1024' => [TakeQuality::Low, TakeSize::Landscape, 420],
        'low 1024x1536' => [TakeQuality::Low, TakeSize::Portrait, 420],
        'medium 1024x1024' => [TakeQuality::Medium, TakeSize::Square, 1_100],
        'medium 1536x1024' => [TakeQuality::Medium, TakeSize::Landscape, 1_650],
        'medium 1024x1536' => [TakeQuality::Medium, TakeSize::Portrait, 1_650],
        'high 1024x1024' => [TakeQuality::High, TakeSize::Square, 4_300],
        'high 1536x1024' => [TakeQuality::High, TakeSize::Landscape, 6_450],
        'high 1024x1536' => [TakeQuality::High, TakeSize::Portrait, 6_450],
    ]);

    it('uses the reasoning minimum for the minimum and the maximum for the maximum', function (TakeQuality $quality, int $min, int $max): void {
        $estimate = $this->estimator->estimateForCharacters(0, TakeSize::Square, $quality, 0, ($this->onlyRate)('reasoning', 12_500_000_000));

        expect($estimate->minCost->toInt())->toBe($min * 12_500)
            ->and($estimate->maxCost->toInt())->toBe($max * 12_500);
    })->with([
        'low' => [TakeQuality::Low, 0, 500],
        'medium' => [TakeQuality::Medium, 200, 2_000],
        'high' => [TakeQuality::High, 1_000, 8_000],
    ]);

    it('bills 765 input image tokens per reference image', function (int $references): void {
        $estimate = $this->estimator->estimateForCharacters(0, TakeSize::Square, TakeQuality::Low, $references, ($this->onlyRate)('input_image', 10_000_000_000));

        expect($estimate->minCost->toInt())->toBe($references * 765 * 10_000)
            ->and($estimate->maxCost->toInt())->toBe($references * 765 * 10_000);
    })->with([0, 1, 2, 3, 4]);

    it('bounds text tokens by floor(est × 85%) and ceil(est × 115%), est = ceil(chars / 4)', function (int $characters, int $minTokens, int $maxTokens): void {
        // 1,000,000 nanodollars per 1M tokens = 1 nanodollar per token: cost == token count.
        $estimate = $this->estimator->estimateForCharacters($characters, TakeSize::Square, TakeQuality::Low, 0, ($this->onlyRate)('input_text', 1_000_000));

        expect($estimate->minCost->toInt())->toBe($minTokens)
            ->and($estimate->maxCost->toInt())->toBe($maxTokens);
    })->with([
        'empty' => [0, 0, 0],
        'one char (est 1)' => [1, 0, 2],
        'four chars (est 1)' => [4, 0, 2],
        'five chars (est 2)' => [5, 1, 3],
        'twenty chars (est 5)' => [20, 4, 6],
        'A06 / take #10: 64 chars (est 16)' => [64, 13, 19],
        'docs example: 400 chars (est 100)' => [400, 85, 115],
        'limit: 4000 chars (est 1000)' => [4_000, 850, 1_150],
    ]);
});

describe('invariants', function (): void {
    it('keeps min <= max and charges exactly cost × 1.25 for every kind and combination', function (): void {
        $kinds = ['input_text' => 5_000_000_000, 'input_image' => 10_000_000_000, 'output' => 40_000_000_000, 'reasoning' => 12_500_000_000];
        $rateSets = ['all kinds' => $this->rates];

        foreach ($kinds as $kind => $rate) {
            $rateSets["only {$kind}"] = ($this->onlyRate)($kind, $rate);
        }

        $checked = 0;

        foreach ($rateSets as $label => $rates) {
            foreach (TakeQuality::cases() as $quality) {
                foreach (TakeSize::cases() as $size) {
                    foreach (range(0, 4) as $references) {
                        foreach ([0, 1, 2, 3, 4, 5, 17, 63, 64, 65, 399, 400, 401, 3_999, 4_000] as $characters) {
                            $estimate = $this->estimator->estimateForCharacters($characters, $size, $quality, $references, $rates);
                            $case = "{$label}, {$quality->value} {$size->value}, {$references} refs, {$characters} chars";

                            expect($estimate->minCost->toInt())->toBeLessThanOrEqual($estimate->maxCost->toInt(), $case)
                                ->and($estimate->minCharge->toInt())->toBeLessThanOrEqual($estimate->maxCharge->toInt(), $case)
                                // Exact markup: every per-token rate in nanodollars is a multiple of 4.
                                ->and($estimate->minCost->toInt() % 4)->toBe(0, $case)
                                ->and($estimate->maxCost->toInt() % 4)->toBe(0, $case)
                                ->and($estimate->minCharge->toInt() * 100)->toBe($estimate->minCost->toInt() * 125, $case)
                                ->and($estimate->maxCharge->toInt() * 100)->toBe($estimate->maxCost->toInt() * 125, $case);

                            $checked++;
                        }
                    }
                }
            }
        }

        expect($checked)->toBe(5 * 3 * 3 * 5 * 15);
    });

    it('rounds a non-exact cost up with the ceil guard (synthetic odd rate)', function (): void {
        // 280 output tokens × 1,000,001 nanodollars per 1M = 280.00028 nanodollars ⇒ 281.
        $estimate = $this->estimator->estimateForCharacters(0, TakeSize::Square, TakeQuality::Low, 0, ($this->onlyRate)('output', 1_000_001));

        expect($estimate->minCost->toInt())->toBe(281)
            ->and($estimate->maxCost->toInt())->toBe(281)
            // 281 × 1.25 = 351.25 ⇒ 352: at most 1 nanodollar in the platform's favor.
            ->and($estimate->maxCharge->toInt())->toBe(352);
    });

    it('rounds a single token at a one-nanodollar-per-1M rate up to one nanodollar', function (): void {
        $estimate = $this->estimator->estimateForCharacters(4, TakeSize::Square, TakeQuality::Low, 0, ($this->onlyRate)('input_text', 1));

        // est 1 token: min 0 tokens ⇒ 0, max 2 tokens × 1 / 1M ⇒ ceil ⇒ 1.
        expect($estimate->minCost->toInt())->toBe(0)
            ->and($estimate->maxCost->toInt())->toBe(1);
    });

    it('applies a different markup when given one', function (): void {
        $estimate = $this->estimator->estimateForCharacters(400, TakeSize::Landscape, TakeQuality::High, 2, $this->rates, 0);

        expect($estimate->maxCharge->toInt())->toBe(373_875_000);
    });
});

describe('character counting', function (): void {
    it('counts Unicode characters, not bytes or UTF-16 units', function (string $prompt, int $characters): void {
        $fromPrompt = $this->estimator->estimate($prompt, TakeSize::Square, TakeQuality::Medium, 0, $this->rates);
        $fromCount = $this->estimator->estimateForCharacters($characters, TakeSize::Square, TakeQuality::Medium, 0, $this->rates);

        expect($fromPrompt)->toEqual($fromCount);
    })->with([
        'ascii' => ['Night version with stars', 24],
        'accents (2 bytes each)' => ['Café crème, señor', 17],
        'emoji (4 bytes, 2 UTF-16 units each)' => ['🌙🌙🌙🌙🌙', 5],
        'emoji with skin tone (2 code points)' => ['👍🏽', 2],
        'CJK (3 bytes each)' => ['灯台の夜景', 5],
    ]);

    it('gives a different estimate than a byte count would for multi-byte prompts', function (): void {
        // 5 emoji: 5 characters (est 2 tokens) but 20 bytes (est 5 tokens).
        $estimate = $this->estimator->estimate('🌙🌙🌙🌙🌙', TakeSize::Square, TakeQuality::Low, 0, ($this->onlyRate)('input_text', 1_000_000));

        expect($estimate->maxCost->toInt())->toBe(3);
    });

    it('accepts exactly 4,000 multi-byte characters and rejects 4,001', function (string $character): void {
        $atLimit = $this->estimator->estimate(str_repeat($character, 4_000), TakeSize::Square, TakeQuality::Low, 0, ($this->onlyRate)('input_text', 1_000_000));

        expect($atLimit->maxCost->toInt())->toBe(1_150)
            ->and(fn () => $this->estimator->estimate(str_repeat($character, 4_001), TakeSize::Square, TakeQuality::Low, 0, $this->rates))
            ->toThrow(InvalidArgumentException::class);
    })->with(['a', 'é', '🌙']);
});

describe('invalid input', function (): void {
    it('rejects counts outside the provider limits', function (int $characters, int $references): void {
        expect(fn () => $this->estimator->estimateForCharacters($characters, TakeSize::Square, TakeQuality::Low, $references, $this->rates))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'negative characters' => [-1, 0],
        'over 4000 characters' => [4_001, 0],
        'negative references' => [10, -1],
        'more than 4 references' => [10, 5],
    ]);

    it('rejects a rates snapshot with a missing or negative kind', function (): void {
        $missing = $this->rates;
        unset($missing['reasoning']);

        expect(fn () => $this->estimator->estimateForCharacters(10, TakeSize::Square, TakeQuality::Low, 0, $missing))
            ->toThrow(InvalidArgumentException::class, 'reasoning')
            ->and(fn () => $this->estimator->estimateForCharacters(10, TakeSize::Square, TakeQuality::Low, 0, [...$this->rates, 'output' => -1]))
            ->toThrow(InvalidArgumentException::class, 'output');
    });

    it('refuses to overflow instead of silently turning into a float', function (): void {
        expect(fn () => $this->estimator->estimateForCharacters(0, TakeSize::Square, TakeQuality::High, 0, ($this->onlyRate)('output', PHP_INT_MAX)))
            ->toThrow(OverflowException::class);
    });
});

describe('demo dataset (.claude/specs/2-product-design/demo-dataset.md)', function (): void {
    it('reproduces the estimate range and hold of each take', function (
        int $characters,
        TakeSize $size,
        TakeQuality $quality,
        int $references,
        int $minCost,
        int $maxCost,
        int $minCharge,
        int $maxCharge,
        string $minDisplay,
        string $maxDisplay,
    ): void {
        $estimate = $this->estimator->estimateForCharacters($characters, $size, $quality, $references, $this->rates);

        expect($estimate->minCost->toInt())->toBe($minCost)
            ->and($estimate->maxCost->toInt())->toBe($maxCost)
            ->and($estimate->minCharge->toInt())->toBe($minCharge)
            ->and($estimate->maxCharge->toInt())->toBe($maxCharge)
            // D-021: estimate minimum rounded down, maximum rounded up, 4 decimals.
            ->and($estimate->minCharge->format(4, RoundingMode::Floor))->toBe($minDisplay)
            ->and($estimate->maxCharge->format(4, RoundingMode::Ceil))->toBe($maxDisplay);
    })->with([
        // A06 money panel and "est. $0.0677–$0.0960" / "Generate take · up to $0.0960".
        '#10: 1024x1024 medium, 1 ref, 64 chars' => [64, TakeSize::Square, TakeQuality::Medium, 1, 54_215_000, 76_745_000, 67_768_750, 95_931_250, '$0.0677', '$0.0960'],
        // "Estimate $0.0581 – $0.0863", hold 86,293,750 (queued).
        '#11: 1024x1024 medium, 0 refs, "Night version with stars"' => [24, TakeSize::Square, TakeQuality::Medium, 0, 46_525_000, 69_035_000, 58_156_250, 86_293_750, '$0.0581', '$0.0863'],
        // Hold 113,825,000 (processing).
        '#12: 1024x1536 medium, 0 refs, 37 chars' => [37, TakeSize::Portrait, TakeQuality::Medium, 0, 68_540_000, 91_060_000, 85_675_000, 113_825_000, '$0.0856', '$0.1139'],
        // Hold 113,843,750 (pending, waiting for capacity).
        '#13: 1536x1024 medium, 0 refs, 50 chars' => [50, TakeSize::Landscape, TakeQuality::Medium, 0, 68_555_000, 91_075_000, 85_693_750, 113_843_750, '$0.0856', '$0.1139'],
    ]);

    it('holds $0.3139625 for the three in-progress takes (#11 + #12 + #13)', function (): void {
        $holds = [
            $this->estimator->estimate('Night version with stars', TakeSize::Square, TakeQuality::Medium, 0, $this->rates),
            $this->estimator->estimateForCharacters(37, TakeSize::Portrait, TakeQuality::Medium, 0, $this->rates),
            $this->estimator->estimateForCharacters(50, TakeSize::Landscape, TakeQuality::Medium, 0, $this->rates),
        ];

        $total = array_reduce($holds, fn (Nanodollars $sum, $estimate): Nanodollars => $sum->plus($estimate->maxCharge), Nanodollars::zero());

        expect($total->toInt())->toBe(313_962_500)
            ->and($total->formatExact())->toBe('$0.3139625');
    });

    it('brackets the actual cost of every completed take', function (string $prompt, TakeSize $size, TakeQuality $quality, int $references, int $actualCost): void {
        $estimate = $this->estimator->estimate($prompt, $size, $quality, $references, $this->rates);

        expect($actualCost)->toBeGreaterThanOrEqual($estimate->minCost->toInt())
            ->toBeLessThanOrEqual($estimate->maxCost->toInt());
    })->with([
        '#6' => ['Portrait crop, fog', TakeSize::Portrait, TakeQuality::Medium, 0, 81_025_000],
        '#7' => ['Black and white version', TakeSize::Square, TakeQuality::Medium, 0, 55_280_000],
        '#8' => ['Warmer light, low sun', TakeSize::Landscape, TakeQuality::Medium, 1, 86_180_000],
        '#10' => [str_repeat('x', 64), TakeSize::Square, TakeQuality::Medium, 1, 59_230_000],
    ]);
});

it('contains no float or built-in rounding helpers in the pricing code', function (string $path): void {
    $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 3).'/'.$path));
    $code = implode('', array_map(
        fn (array|string $token): string => is_array($token)
            ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
            : $token,
        $tokens,
    ));

    expect(array_filter($tokens, fn (array|string $token): bool => is_array($token) && $token[0] === T_DNUMBER))->toBe([]);

    foreach (['float', 'round(', 'floatval', 'number_format(', 'floor(', 'ceil(', 'bcmath', '**'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("{$path} must not use [{$forbidden}].");
    }
})->with([
    'app/Support/Pricing/TakeEstimator.php',
    'app/Support/Pricing/PixelforgeTokenTable.php',
    'app/Support/Pricing/AiPricing.php',
    'app/DTOs/Billing/EstimateDTO.php',
    'config/ai-pricing.php',
]);
