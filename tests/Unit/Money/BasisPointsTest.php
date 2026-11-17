<?php

declare(strict_types=1);

use App\Support\Money\BasisPoints;

it('returns integer basis points, null without a whole', function (int $part, int $whole, ?int $expected): void {
    expect(BasisPoints::ratio($part, $whole))->toBe($expected);
})->with([
    'margin invariant' => [14_807_500, 74_037_500, 2000],
    'share' => [1, 3, 3333],
    'negative' => [-125, 10_000, -125],
    'no whole' => [5, 0, null],
    'above the overflow limit' => [PHP_INT_MAX - 7, PHP_INT_MAX, 10000],
    'above the limit, not equal' => [intdiv(PHP_INT_MAX, 3), PHP_INT_MAX, 3333],
    'large equal parts' => [5_000_000_000_000_000, 10_000_000_000_000_000, 5000],
]);
