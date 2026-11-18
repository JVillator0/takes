<?php

declare(strict_types=1);

namespace App\Support\Money;

use OverflowException;

/**
 * A ratio of two nanodollar amounts in integer basis points (margin, share of spend): exactly
 * trunc(part × 10,000 ÷ whole), toward zero, for any int inputs. Integer math only: long division on the
 * magnitudes builds the four basis-point digits from the remainder by repeated addition, so no intermediate
 * value ever exceeds the int range; the sign is applied at the end.
 */
final class BasisPoints
{
    private const int DIGITS = 4;

    public static function ratio(int $part, int $whole): ?int
    {
        if ($whole === 0) {
            return null;
        }

        if ($part === PHP_INT_MIN || $whole === PHP_INT_MIN) {
            throw new OverflowException('A basis-point ratio needs operands within ±PHP_INT_MAX.');
        }

        $negative = ($part < 0) !== ($whole < 0);
        $numerator = abs($part);
        $denominator = abs($whole);

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        // The four digits are appended after the quotient: q × 10,000 + 9,999 must fit.
        if ($quotient > intdiv(PHP_INT_MAX - 9_999, 10_000)) {
            throw new OverflowException('The ratio does not fit in basis points.');
        }

        $bps = $quotient;

        for ($digit = 0; $digit < self::DIGITS; $digit++) {
            [$next, $remainder] = self::timesTen($remainder, $denominator);
            $bps = $bps * 10 + $next;
        }

        return $negative ? -$bps : $bps;
    }

    /**
     * (r × 10) ÷ d as [digit, remainder] for 0 ≤ r < d, by adding r ten times and wrapping at d, so the running
     * sum stays below d and never overflows.
     *
     * @return array{0: int, 1: int}
     */
    private static function timesTen(int $remainder, int $denominator): array
    {
        $digit = 0;
        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            if ($sum >= $denominator - $remainder) {
                $sum -= $denominator - $remainder;
                $digit++;
            } else {
                $sum += $remainder;
            }
        }

        return [$digit, $sum];
    }
}
