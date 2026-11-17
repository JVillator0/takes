<?php

declare(strict_types=1);

use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Support\Money\ProviderCost;
use Tests\Support\Billing;

/*
 * cost = ceil(Σ units × rate / 1_000_000), charge = ceil(cost × 1.25)
 * (spec 3-03 §2–§3), integer math only.
 */

it('prices the example usage exactly', function (): void {
    $result = ProviderCost::calculate(Billing::exampleUnits(), Billing::pixelforgeRates());

    expect($result->cost->toInt())->toBe(Billing::EXAMPLE_COST)
        ->and($result->roundedUp)->toBeFalse()
        ->and($result->cost->withMarkup()->toInt())->toBe(Billing::EXAMPLE_CHARGE);
});

it('charges cost + 25% exactly, with no rounding, for every kind of every provider', function (string $kind, int $ratePerMillion): void {
    $rates = new RatesSnapshotDTO([$kind => $ratePerMillion]);

    foreach ([1, 2, 3, 7, 115, 765, 1_000, 4_097, 6_000, 99_999, 1_000_000] as $tokens) {
        $result = ProviderCost::calculate(new UsageUnitsDTO([$kind => $tokens]), $rates);
        $cost = $result->cost->toInt();
        $charge = $result->cost->withMarkup()->toInt();

        // Each term divides exactly: cost = tokens × per-token rate.
        expect($result->roundedUp)->toBeFalse()
            ->and($cost)->toBe($tokens * intdiv($ratePerMillion, 1_000_000))
            // Exact markup: 4 × charge = 5 × cost, no rounding nanodollar.
            ->and($charge * 4)->toBe($cost * 5);
    }
})->with([
    'pixelforge input_text' => ['input_text', 5_000_000_000],
    'pixelforge input_image' => ['input_image', 10_000_000_000],
    'pixelforge output' => ['output', 40_000_000_000],
    'pixelforge reasoning' => ['reasoning', 12_500_000_000],
    'anthropic input_text' => ['input_text', 100_000_000],
    'anthropic output' => ['output', 500_000_000],
]);

it('rounds a fractional cost up to the next nanodollar and says so', function (): void {
    // Synthetic odd rate: $0.000001 per 1M tokens = 1,000 nanodollars per 1M,
    // so 1 token costs 0.001 nanodollars.
    $rates = new RatesSnapshotDTO(['output' => 1_000]);

    $one = ProviderCost::calculate(new UsageUnitsDTO(['output' => 1]), $rates);
    $thousand = ProviderCost::calculate(new UsageUnitsDTO(['output' => 1_000]), $rates);
    $thousandAndOne = ProviderCost::calculate(new UsageUnitsDTO(['output' => 1_001]), $rates);

    expect($one->cost->toInt())->toBe(1)->and($one->roundedUp)->toBeTrue()
        ->and($thousand->cost->toInt())->toBe(1)->and($thousand->roundedUp)->toBeFalse()
        ->and($thousandAndOne->cost->toInt())->toBe(2)->and($thousandAndOne->roundedUp)->toBeTrue()
        // The markup ceil is at most 1 nanodollar in the platform's favor: 1 × 1.25 ⇒ 2.
        ->and($one->cost->withMarkup()->toInt())->toBe(2);
});

it('costs nothing for no tokens', function (): void {
    $result = ProviderCost::calculate(new UsageUnitsDTO([]), Billing::pixelforgeRates());

    expect($result->cost->toInt())->toBe(0)->and($result->roundedUp)->toBeFalse();
});

it('refuses to price tokens of a kind the rates do not cover', function (): void {
    ProviderCost::calculate(new UsageUnitsDTO(['input_image' => 10]), Billing::anthropicRates());
})->throws(InvalidArgumentException::class, 'No rate for usage kind [input_image]');

it('ignores a missing rate when the kind has zero tokens', function (): void {
    $result = ProviderCost::calculate(new UsageUnitsDTO(['input_text' => 10, 'input_image' => 0]), Billing::anthropicRates());

    expect($result->cost->toInt())->toBe(1_000);
});

it('detects overflow instead of wrapping', function (): void {
    ProviderCost::calculate(new UsageUnitsDTO(['output' => PHP_INT_MAX]), new RatesSnapshotDTO(['output' => 2]));
})->throws(OverflowException::class);

it('detects overflow of the sum', function (): void {
    $half = intdiv(PHP_INT_MAX, 2) + 1;

    ProviderCost::calculate(
        new UsageUnitsDTO(['input_text' => $half, 'output' => $half]),
        new RatesSnapshotDTO(['input_text' => 1, 'output' => 1]),
    );
})->throws(OverflowException::class);
