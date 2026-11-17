<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Support\Money\Nanodollars;

/**
 * Exact amounts for the billing page and invoices (D-042): Nanodollars
 * formats, this only adds the explicit sign of a credit ("+$10.00").
 */
final class MoneyFormat
{
    public static function signedExact(Nanodollars $amount): string
    {
        return $amount->isNegative() || $amount->isZero()
            ? $amount->formatExact()
            : '+'.$amount->formatExact();
    }
}
