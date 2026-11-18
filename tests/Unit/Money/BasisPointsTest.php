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

it('refuses a ratio that does not fit in basis points, at the exact boundary too', function (int $part, int $whole): void {
    expect(fn () => BasisPoints::ratio($part, $whole))->toThrow(OverflowException::class);
})->with([
    'far over' => [PHP_INT_MAX, 1],
    'quotient fits, digits do not' => [2_767_011_611_056_433, 3],
    'negative at the boundary' => [-2_767_011_611_056_433, 3],
]);

it('accepts every ratio whose basis points fit, judged on the actual digits', function (int $part, int $whole, int $bps): void {
    expect(BasisPoints::ratio($part, $whole))->toBe($bps)
        ->and(BasisPoints::ratio(-$part, $whole))->toBe(-$bps);
})->with([
    'largest quotient, no digits' => [922_337_203_685_477, 1, 9_223_372_036_854_770_000],
    'largest quotient, digits that fit' => [2_767_011_611_056_432, 3, 9_223_372_036_854_773_333],
]);
