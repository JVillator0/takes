<?php

declare(strict_types=1);

use App\Enums\Billing\Provider;
use App\Support\Money\TokenCost;
use App\Support\Pricing\AiPricing;
use Illuminate\Config\Repository;
use Psr\Log\NullLogger;

beforeEach(function (): void {
    $this->pricing = new AiPricing(new Repository([
        'ai-pricing' => require dirname(__DIR__, 3).'/config/ai-pricing.php',
    ]));
});

it('stores the documented Haiku 5.5 rates as decimal strings', function (): void {
    $config = require dirname(__DIR__, 3).'/config/ai-pricing.php';

    // https://platform.claude.com/docs/en/about-claude/pricing, model pricing table,
    // "Claude Haiku 5.5 (for prompts up to 100,000 tokens)", retrieved 2026-11-17:
    // base input $0.10 / MTok, output $0.50 / MTok.
    expect($config['anthropic']['claude-haiku-5-5'])->toBe([
        'input_text' => '0.10',
        'output' => '0.50',
    ]);
});

it('resolves Haiku 5.5 into integer nanodollars per 1M tokens', function (): void {
    $rates = $this->pricing->rates(Provider::Anthropic, 'claude-haiku-5-5');

    expect($rates)->toBe(['input_text' => 100_000_000, 'output' => 500_000_000])
        // 100 and 500 nanodollars per token (spec 3-03 §2, demo dataset).
        ->and($rates['input_text'] % 1_000_000)->toBe(0)
        ->and(intdiv($rates['input_text'], 1_000_000))->toBe(100)
        ->and(intdiv($rates['output'], 1_000_000))->toBe(500);
});

it('reproduces the demo AI suggestion: 1,500 in / 200 out ⇒ cost 250,000, charge 312,500', function (): void {
    $rates = $this->pricing->rates(Provider::Anthropic, 'claude-haiku-5-5');
    $cost = (new TokenCost(new NullLogger))->compute(['input_text' => 1_500, 'output' => 200], $rates);

    expect($cost->toInt())->toBe(250_000)
        ->and($cost->withMarkup()->toInt())->toBe(312_500);
});

it('refuses a model or provider with no configured rates', function (Provider $provider, string $model): void {
    expect(fn () => $this->pricing->rates($provider, $model))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown model' => [Provider::Anthropic, 'claude-haiku-4-5'],
    'Pixelforge (rates come from /v1/pricing)' => [Provider::Pixelforge, 'claude-haiku-5-5'],
]);

it('refuses a rate that is not a decimal string', function (mixed $rate): void {
    $pricing = new AiPricing(new Repository([
        'ai-pricing' => ['anthropic' => ['claude-haiku-5-5' => ['input_text' => $rate]]],
    ]));

    expect(fn () => $pricing->rates(Provider::Anthropic, 'claude-haiku-5-5'))->toThrow(InvalidArgumentException::class);
})->with([
    'float' => [0.1],
    'int' => [1],
    'scientific string' => ['1e-1'],
    'negative string' => ['-0.10'],
]);
