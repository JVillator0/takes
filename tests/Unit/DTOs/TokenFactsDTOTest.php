<?php

declare(strict_types=1);

use App\DTOs\Pixelforge\TokenFactsDTO;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;

beforeEach(function (): void {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Pricing/pixelforge-docs-pricing.json'), true, flags: JSON_THROW_ON_ERROR);

    /** The docs' GET /v1/pricing example response, decoded as the HTTP client would. */
    $this->pricing = $fixture['response'];
});

it('parses the documented GET /v1/pricing response into the documented defaults', function (): void {
    $facts = TokenFactsDTO::fromPricingArray($this->pricing);

    expect($facts)->toEqual(TokenFactsDTO::defaults())
        ->and($facts->charactersPerTextToken)->toBe(4)
        ->and($facts->textTokenVarianceBps)->toBe(1_500)
        ->and($facts->tokensPerReferenceImage)->toBe(765)
        ->and($facts->outputTokensFor(TakeQuality::High, TakeSize::Landscape))->toBe(6_450)
        ->and($facts->reasoningTokensFor(TakeQuality::Medium))->toBe(['min' => 200, 'max' => 2_000]);
});

it('parses the variance as integer basis points from a JSON number or a string', function (mixed $variance, int $bps): void {
    $pricing = $this->pricing;
    $pricing['tokens']['text_token_variance'] = $variance;

    expect(TokenFactsDTO::fromPricingArray($pricing)->textTokenVarianceBps)->toBe($bps);
})->with([
    'JSON number' => [0.15, 1_500],
    'string (config fallback)' => ['0.15', 1_500],
    'four decimals' => ['0.0125', 125],
    'zero' => [0, 0],
]);

it('reads changed token tables instead of the documented copy', function (): void {
    $pricing = $this->pricing;
    $pricing['tokens']['per_reference_image'] = 800;
    $pricing['tokens']['output']['high']['1536x1024'] = 7_000;

    $facts = TokenFactsDTO::fromPricingArray($pricing);

    expect($facts->tokensPerReferenceImage)->toBe(800)
        ->and($facts->outputTokensFor(TakeQuality::High, TakeSize::Landscape))->toBe(7_000);
});

it('rejects a malformed tokens block', function (Closure $break): void {
    $pricing = $this->pricing;
    $break($pricing);

    expect(fn () => TokenFactsDTO::fromPricingArray($pricing))->toThrow(InvalidArgumentException::class);
})->with([
    'no tokens block' => [function (array &$p): void {
        unset($p['tokens']);
    }],
    'zero characters per token' => [function (array &$p): void {
        $p['tokens']['characters_per_text_token'] = 0;
    }],
    'characters per token as a string' => [function (array &$p): void {
        $p['tokens']['characters_per_text_token'] = '4';
    }],
    'variance of 1' => [function (array &$p): void {
        $p['tokens']['text_token_variance'] = 1;
    }],
    'variance with 5 decimals' => [function (array &$p): void {
        $p['tokens']['text_token_variance'] = '0.15001';
    }],
    'negative variance' => [function (array &$p): void {
        $p['tokens']['text_token_variance'] = '-0.15';
    }],
    'missing size' => [function (array &$p): void {
        unset($p['tokens']['output']['low']['1024x1536']);
    }],
    'output as a float' => [function (array &$p): void {
        $p['tokens']['output']['low']['1024x1024'] = 280.5;
    }],
    'negative output' => [function (array &$p): void {
        $p['tokens']['output']['low']['1024x1024'] = -1;
    }],
    'missing quality' => [function (array &$p): void {
        unset($p['tokens']['reasoning']['high']);
    }],
    'reasoning min above max' => [function (array &$p): void {
        $p['tokens']['reasoning']['low'] = ['min' => 600, 'max' => 500];
    }],
    'negative reference tokens' => [function (array &$p): void {
        $p['tokens']['per_reference_image'] = -765;
    }],
    'reference tokens that would overflow' => [function (array &$p): void {
        $p['tokens']['per_reference_image'] = PHP_INT_MAX;
    }],
    'characters per token that would overflow intdiv' => [function (array &$p): void {
        $p['tokens']['characters_per_text_token'] = PHP_INT_MAX;
    }],
    'output that would overflow the cost' => [function (array &$p): void {
        $p['tokens']['output']['high']['1536x1024'] = PHP_INT_MAX;
    }],
    'reasoning that would overflow the cost' => [function (array &$p): void {
        $p['tokens']['reasoning']['high']['max'] = PHP_INT_MAX;
    }],
]);

it('bounds the constructor too, so a hand-built DTO cannot overflow the estimator', function (): void {
    $defaults = TokenFactsDTO::defaults();

    expect(fn () => new TokenFactsDTO(TokenFactsDTO::MAX_CHARACTERS_PER_TEXT_TOKEN + 1, 1_500, 765, $defaults->outputTokens, $defaults->reasoningTokens))
        ->toThrow(InvalidArgumentException::class, 'characters_per_text_token')
        ->and(fn () => new TokenFactsDTO(4, 1_500, TokenFactsDTO::MAX_TOKENS_PER_KIND + 1, $defaults->outputTokens, $defaults->reasoningTokens))
        ->toThrow(InvalidArgumentException::class, 'per_reference_image');
});

it('parses the JSON number variance the same whatever the precision ini (#107)', function (): void {
    $saved = ['precision' => ini_get('precision'), 'serialize_precision' => ini_get('serialize_precision')];
    ini_set('precision', '17');
    ini_set('serialize_precision', '17');

    try {
        $pricing = $this->pricing;
        $pricing['tokens']['text_token_variance'] = 0.15;

        expect(TokenFactsDTO::fromPricingArray($pricing)->textTokenVarianceBps)->toBe(1_500);
    } finally {
        foreach ($saved as $key => $value) {
            ini_set($key, (string) $value);
        }
    }
});
