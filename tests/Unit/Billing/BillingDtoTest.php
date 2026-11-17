<?php

declare(strict_types=1);

use App\DTOs\Billing\EstimateDTO;
use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Billing\UsageKind;
use App\Support\Money\Nanodollars;

it('reads tokens by kind and null for a kind not reported', function (): void {
    $units = new UsageUnitsDTO(['input_text' => 12, 'output' => 0]);

    expect($units->tokens(UsageKind::InputText))->toBe(12)
        ->and($units->tokens(UsageKind::Output))->toBe(0)
        ->and($units->tokens(UsageKind::Reasoning))->toBeNull();
});

it('rejects unknown kinds, non-integer and negative counts', function (array $tokens): void {
    new UsageUnitsDTO($tokens);
})->throws(InvalidArgumentException::class)->with([
    'unknown kind' => [['cache_read' => 1]],
    'negative' => [['output' => -1]],
    'float' => [['output' => 1.5]],
    'numeric string' => [['output' => '12']],
    'null' => [['output' => null]],
]);

it('rejects unknown kinds, non-integer and negative rates', function (array $rates): void {
    new RatesSnapshotDTO($rates);
})->throws(InvalidArgumentException::class)->with([
    'unknown kind' => [['cache_read' => 1]],
    'negative' => [['output' => -1]],
    'float' => [['output' => 40.0]],
    'decimal string' => [['output' => '40.00']],
    'null' => [['output' => null]],
]);

it('round-trips a stored rates snapshot and refuses non-integer rates', function (): void {
    $rates = RatesSnapshotDTO::fromStored(['output' => 40_000_000_000]);

    expect($rates->toArray())->toBe(['output' => 40_000_000_000])
        ->and($rates->rate(UsageKind::Output))->toBe(40_000_000_000)
        ->and($rates->rate(UsageKind::Reasoning))->toBeNull();

    RatesSnapshotDTO::fromStored(['output' => '40.00']);
})->throws(InvalidArgumentException::class, 'must be integer nanodollars');

it('derives the charges of an estimate from its costs with the 25% markup', function (): void {
    $estimate = EstimateDTO::fromCosts(Nanodollars::fromInt(100_000_000), Nanodollars::fromInt(373_875_000));

    expect($estimate->minCharge->toInt())->toBe(125_000_000)
        ->and($estimate->maxCharge->toInt())->toBe(467_343_750);
});

it('rejects an inverted or negative estimate', function (int $min, int $max): void {
    EstimateDTO::fromCosts(Nanodollars::fromInt($min), Nanodollars::fromInt($max));
})->throws(InvalidArgumentException::class)->with([
    'min above max' => [2, 1],
    'negative' => [-1, 1],
]);
