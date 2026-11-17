<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * How Nanodollars::format() rounds a rounded display amount (D-021).
 *
 * - Floor: estimate minimum (never overstated).
 * - Ceil: estimate maximum, "up to" (never understated).
 * - HalfUp: charges in lists (half away from zero).
 */
enum RoundingMode
{
    case Floor;
    case Ceil;
    case HalfUp;
}
