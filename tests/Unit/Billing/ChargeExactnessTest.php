<?php

declare(strict_types=1);

use App\Support\Money\TokenCost;
use Tests\Support\Billing;
use Tests\Support\RecordingLogger;

/*
 * cost = ceil(Σ units × rate / 1M) (TokenCost, covered in TokenCostTest),
 * then charge = ceil(cost × 1.25) (spec 3-03 §2–§3). These tests pin the
 * charge side for every kind of every provider.
 */

beforeEach(function (): void {
    $this->logger = new RecordingLogger;
    $this->tokenCost = new TokenCost($this->logger);
});

it('prices the example usage exactly', function (): void {
    $cost = $this->tokenCost->compute(Billing::exampleUnits()->tokens, Billing::pixelforgeRates()->toArray());

    expect($cost->toInt())->toBe(Billing::EXAMPLE_COST)
        ->and($cost->withMarkup()->toInt())->toBe(Billing::EXAMPLE_CHARGE)
        ->and($this->logger->records)->toBe([]);
});

it('charges cost + 25% exactly, with no rounding, for every kind of every provider', function (string $kind, int $ratePerMillion): void {
    foreach ([1, 2, 3, 7, 115, 765, 1_000, 4_097, 6_000, 99_999, 1_000_000] as $tokens) {
        $cost = $this->tokenCost->compute([$kind => $tokens], [$kind => $ratePerMillion])->toInt();
        $charge = Billing::nanos($cost)->withMarkup()->toInt();

        // Each term divides exactly: cost = tokens × per-token rate,
        // and the markup is exact: 4 × charge = 5 × cost.
        expect($cost)->toBe($tokens * intdiv($ratePerMillion, 1_000_000))
            ->and($charge * 4)->toBe($cost * 5);
    }

    expect($this->logger->records)->toBe([]);
})->with([
    'pixelforge input_text' => ['input_text', 5_000_000_000],
    'pixelforge input_image' => ['input_image', 10_000_000_000],
    'pixelforge output' => ['output', 40_000_000_000],
    'pixelforge reasoning' => ['reasoning', 12_500_000_000],
    'anthropic input_text' => ['input_text', 100_000_000],
    'anthropic output' => ['output', 500_000_000],
]);

it('rounds up at most one nanodollar per step with a synthetic odd rate', function (): void {
    // $0.000001 per 1M tokens = 1,000 nanodollars per 1M: 1 token = 0.001 nanodollars.
    $cost = $this->tokenCost->compute(['output' => 1], ['output' => 1_000]);

    expect($cost->toInt())->toBe(1)
        ->and($this->logger->records)->toHaveCount(1)
        // The markup ceil is in the platform's favor: 1 × 1.25 ⇒ 2.
        ->and($cost->withMarkup()->toInt())->toBe(2);
});
