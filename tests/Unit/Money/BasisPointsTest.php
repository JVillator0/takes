<?php

declare(strict_types=1);

use App\Support\Money\BasisPoints;

it('returns trunc(part × 10,000 ÷ whole) exactly, null without a whole', function (int $part, int $whole, ?int $expected): void {
    expect(BasisPoints::ratio($part, $whole))->toBe($expected);
})->with([
    'margin invariant' => [14_807_500, 74_037_500, 2000],
    'share' => [1, 3, 3333],
    'negative part' => [-125, 10_000, -125],
    'negative whole' => [125, -10_000, -125],
    'no whole' => [5, 0, null],
    'just below one, above the overflow limit' => [PHP_INT_MAX - 7, PHP_INT_MAX, 9999],
    'exact 20% above the limit' => [200_000_000_000_009, 1_000_000_000_000_045, 2000],
    'large negative part' => [-5_000_000_000_000_000, 9, -5_555_555_555_555_555_555],
    'over one' => [3, 2, 15000],
]);

it('refuses a ratio that does not fit in basis points', function (): void {
    expect(fn () => BasisPoints::ratio(PHP_INT_MAX, 1))->toThrow(OverflowException::class);
});
