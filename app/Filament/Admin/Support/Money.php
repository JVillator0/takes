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
        return $nanos === null ? '-' : number_format(Nanodollars::fromInt($nanos)->toInt());
    }

    /**
     * 2000 bps → "20.0%", rounded half up to one decimal; `-` when there is no ratio.
     */
    public static function percentFromBps(?int $bps): string
    {
        if ($bps === null) {
            return '-';
        }

        $sign = $bps < 0 ? '-' : '';
        $tenths = intdiv(abs($bps) + 5, 10);

        return $sign.intdiv($tenths, 10).'.'.($tenths % 10).'%';
    }
}
