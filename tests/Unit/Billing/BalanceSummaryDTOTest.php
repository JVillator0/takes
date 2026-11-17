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
        'available' => '$10.3336',
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
