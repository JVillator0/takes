<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Support\Money\Nanodollars;

/**
 * How the console prints money and ratios (D-042): exact dollars through Nanodollars::formatExact(), raw integer
 * nanodollars with thousands separators where exactness is the point (A06), and basis points as a percentage
 * with one decimal, all in integer math.
 */
final class Money
{
    public static function exact(int|string|null $nanos): string
    {
        return $nanos === null ? '-' : Nanodollars::fromInt($nanos)->formatExact();
    }

    public static function nanos(int|string|null $nanos): string
    {
        return $nanos === null ? '-' : self::grouped(Nanodollars::fromInt($nanos)->toInt());
    }

    /**
     * An integer with thousands separators, as a string operation (no float conversion at any size).
     */
    public static function grouped(int $value): string
    {
        $digits = (string) abs($value);
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $digits);

        return ($value < 0 ? '-' : '').$grouped;
    }

    /**
     * 2000 bps → "20.0%", rounded half away from zero to one decimal; `-` when there is no ratio. A value that
     * rounds to zero prints "0.0%", never "-0.0%".
     */
    public static function percentFromBps(?int $bps): string
    {
        if ($bps === null) {
            return '-';
        }

        $tenths = intdiv(abs($bps) + 5, 10);
        $sign = $bps < 0 && $tenths > 0 ? '-' : '';

        return $sign.intdiv($tenths, 10).'.'.($tenths % 10).'%';
    }
}
