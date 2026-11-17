<?php

declare(strict_types=1);

use App\Ai\Agents\TakeChangeAgent;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageKind;
use App\Support\Money\TokenCost;
use App\Support\Pricing\AiPricing;
use App\Support\Pricing\SuggestionEstimator;
use Illuminate\Config\Repository;
use Psr\Log\NullLogger;

/*
 * The "Describe a change" hold (spec 8 "Money", D-055):
 * max_cost = (ceil(bytes / 2) + 500) × 100 + 600 × 500, hold = max_cost × 1.25.
 */

beforeEach(function (): void {
    $this->rates = (new AiPricing(new Repository([
        'ai-pricing' => require dirname(__DIR__, 3).'/config/ai-pricing.php',
    ])))->rates(Provider::Anthropic, TakeChangeAgent::MODEL);
    $this->estimator = new SuggestionEstimator(new TokenCost(new NullLogger));
});

it('bounds the output by the agent\'s MaxTokens', function (): void {
    expect(SuggestionEstimator::OUTPUT_BOUND)->toBe(600)
        ->and(TakeChangeAgent::MAX_OUTPUT_TOKENS)->toBe(600);
});

it('computes the hold from the request bytes', function (int $bytes, int $maxCost, int $maxCharge, int $minCost): void {
    $estimate = $this->estimator->estimateForBytes($bytes, $this->rates);

    expect($estimate->maxCost->toInt())->toBe($maxCost)
        ->and($estimate->maxCharge->toInt())->toBe($maxCharge)
        ->and($estimate->minCost->toInt())->toBe($minCost);
})->with([
    // The spike request: 2,010 bytes ⇒ 1,005 + 500 input, 600 output.
    'spike request' => [2_010, 450_500, 563_125, 50_200],
    // Odd byte count rounds the input bound up.
    'odd bytes' => [2_011, 450_600, 563_250, 50_200],
    // Empty request: the overhead and the output bound alone.
    'nothing' => [0, 350_000, 437_500, 0],
]);

it('counts UTF-8 bytes, not characters', function (): void {
    $ascii = $this->estimator->estimate(str_repeat('a', 400), $this->rates);
    $emoji = $this->estimator->estimate(str_repeat('🌊', 100), $this->rates);

    expect($emoji->maxCost->toInt())->toBe($ascii->maxCost->toInt());
});

it('holds more than the spike\'s actual usage (959 + 134 tokens)', function (): void {
    $actualCost = 959 * 100 + 134 * 500;

    expect($this->estimator->estimateForBytes(2_010, $this->rates)->maxCost->toInt())->toBeGreaterThan($actualCost);
});

it('prices exactly the usage kinds the response extractor produces', function (): void {
    expect(array_keys($this->rates))->toEqualCanonicalizing([UsageKind::InputText->value, UsageKind::Output->value])
        ->and(AiPricing::KINDS['anthropic'])->toBe([UsageKind::InputText->value, UsageKind::Output->value]);
});
