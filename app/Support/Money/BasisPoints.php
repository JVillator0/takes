<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * A ratio of two nanodollar amounts in integer basis points (margin, share of spend), truncated toward zero.
 * Integer math only, and overflow-safe: when `part × 10,000` would not fit in an int, both sides are scaled
 * down by 10 until it does, which cannot change the result by more than the truncation it already has.
 */
final class BasisPoints
{
    private const int SCALE = 10_000;

    public static function ratio(int $part, int $whole): ?int
    {
        $limit = intdiv(PHP_INT_MAX, self::SCALE);

        while ($whole !== 0 && (abs($part) > $limit || abs($whole) > $limit)) {
            $part = intdiv($part, 10);
            $whole = intdiv($whole, 10);
        }

        return $whole === 0 ? null : intdiv($part * self::SCALE, $whole);
    }
}
