<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

use Illuminate\Support\Facades\RateLimiter;

/**
 * The admin console's share of the Pixelforge account budget (60 requests/min, shared with every customer's
 * takes): one fixed window of PER_MINUTE requests for all admins together, spent per actual HTTP request. Usage
 * reports spend 1; Find takes reserves its page cap up front and refunds the pages it did not read.
 */
final class AdminRequestBudget
{
    public const string KEY = 'admin-pixelforge';

    public const int PER_MINUTE = 20;

    /**
     * Takes `$requests` from the window, or nothing when they do not fit.
     */
    public static function reserve(int $requests): bool
    {
        if (RateLimiter::increment(self::KEY, 60, $requests) <= self::PER_MINUTE) {
            return true;
        }

        RateLimiter::decrement(self::KEY, 60, $requests);

        return false;
    }

    public static function refund(int $requests): void
    {
        if ($requests > 0) {
            RateLimiter::decrement(self::KEY, 60, $requests);
        }
    }

    public static function remaining(): int
    {
        return RateLimiter::remaining(self::KEY, self::PER_MINUTE);
    }

    public static function availableIn(): int
    {
        return max(1, RateLimiter::availableIn(self::KEY));
    }
}
