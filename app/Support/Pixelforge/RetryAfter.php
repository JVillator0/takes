<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parses a `Retry-After` header (RFC 9110 §10.2.3): either delay-seconds
 * ("5") or an HTTP-date ("Tue, 17 Nov 2026 17:21:36 GMT"). Pixelforge sends
 * seconds; the HTTP-date form is accepted because proxies may rewrite it.
 */
final class RetryAfter
{
    /**
     * @return int|null Seconds to wait (never negative), or null when the
     *                  header is missing or unreadable.
     */
    public static function parse(?string $header, CarbonImmutable $now): ?int
    {
        $value = trim((string) $header);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            // A 10+ digit value would be a bogus delay; strlen guards the int cast.
            return strlen($value) > 9 ? 999_999_999 : (int) $value;
        }

        try {
            $date = CarbonImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        return max(0, $date->getTimestamp() - $now->getTimestamp());
    }
}
