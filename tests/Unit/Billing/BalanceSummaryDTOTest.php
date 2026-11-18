<?php

declare(strict_types=1);

use App\DTOs\Billing\BalanceSummaryDTO;
use App\Support\Money\Nanodollars;

/*
| The balance pill and the 2-01 negative-balance banner (D-024, D-042):
| strings only, 4 decimals on the pill and the banner, exact on hover.
*/

it('formats the pill with 4 decimals and the exact amounts', function (): void {
    $summary = new BalanceSummaryDTO(Nanodollars::fromInt(10_333_581_250), Nanodollars::fromInt(313_962_500));

    expect($summary->toArray())->toBe([
        'available' => '$10.3335',
        'availableExact' => '$10.33358125',
        'onHold' => '$0.3140',
        'onHoldExact' => '$0.3139625',
    ]);
});

it('says by how much the balance is below zero, and nothing at zero or above', function (int $available, ?string $expected): void {
    expect((new BalanceSummaryDTO(Nanodollars::fromInt($available), Nanodollars::zero()))->negativeBy())->toBe($expected);
})->with([
    'below zero' => [-50_000_000, '$0.0500'],
    // Rounded up like the pill's floored negative available (HalfUp would say $0.0123).
    'rounds the debt up' => [-12_345_000, '$0.0124'],
    'zero' => [0, null],
    'positive' => [1, null],
]);

it('says the same debt on the pill and in the negative-balance banner', function (): void {
    $summary = new BalanceSummaryDTO(Nanodollars::fromInt(-12_345_000), Nanodollars::zero());

    expect($summary->toArray()['available'])->toBe('-$0.0124')
        ->and($summary->negativeBy())->toBe('$0.0124');
});

it('rounds on hold up like the cards\' "Up to" and available down (D-021, QA #152)', function (): void {
    // One Square/Low take: the exact hold $0.02191875 is "Up to $0.0220" on the card and the button.
    $hold = Nanodollars::fromInt(21_918_750);
    $summary = new BalanceSummaryDTO(Nanodollars::fromInt(16_000_000_000)->minus($hold), $hold);

    expect($summary->toArray())->toMatchArray([
        'onHold' => '$0.0220',
        'available' => '$15.9780',
    ]);
});

it('never understates on hold nor overstates available', function (int $available, int $held, string $expectedAvailable, string $expectedOnHold): void {
    $summary = (new BalanceSummaryDTO(Nanodollars::fromInt($available), Nanodollars::fromInt($held)))->toArray();

    expect($summary['available'])->toBe($expectedAvailable)
        ->and($summary['onHold'])->toBe($expectedOnHold);
})->with([
    // Each row except "exact" would read differently with half-up rounding.
    'just above a step' => [4_999_899_999, 100_001, '$4.9998', '$0.0002'],
    'below half a step' => [4_999_960_000, 40_000, '$4.9999', '$0.0001'],
    'exact' => [4_999_900_000, 100_000, '$4.9999', '$0.0001'],
    'several holds' => [4_654_875_000, 345_125_000, '$4.6548', '$0.3452'],
    'negative available' => [-12_345_000, 0, '-$0.0124', '$0.0000'],
]);
