<?php

declare(strict_types=1);

use App\DTOs\Billing\BalanceSummaryDTO;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;

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
    'half up' => [-12_345_000, '$0.0123'],
    'zero' => [0, null],
    'positive' => [1, null],
]);

it('rounds on hold up like the cards\' "Up to" and available down (D-021, QA #152)', function (): void {
    // One Square/Low take: the exact hold $0.02191875 is "Up to $0.0220" on the card and the button.
    $hold = Nanodollars::fromInt(21_918_750);
    $summary = new BalanceSummaryDTO(Nanodollars::fromInt(16_000_000_000)->minus($hold), $hold);

    expect($summary->toArray())->toMatchArray([
        'onHold' => $hold->format(4, RoundingMode::Ceil),
        'available' => '$15.9780',
    ])->and($summary->toArray()['onHold'])->toBe('$0.0220');
});

it('never understates on hold nor overstates available at any remainder', function (int $held): void {
    $summary = (new BalanceSummaryDTO(Nanodollars::fromInt(5_000_000_000 - $held), Nanodollars::fromInt($held)))->toArray();

    expect($summary['onHold'])->toBe(Nanodollars::fromInt($held)->format(4, RoundingMode::Ceil))
        ->and($summary['available'])->toBe(Nanodollars::fromInt(5_000_000_000 - $held)->format(4, RoundingMode::Floor));
})->with([
    'just above a step' => [100_001],
    'half' => [50_000],
    'exact' => [100_000],
    'four Medium holds' => [4 * 86_293_750],
]);
