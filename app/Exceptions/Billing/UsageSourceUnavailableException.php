<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * The provider usage report (`billing:verify --usage`) cannot be read, e.g.
 * before the Pixelforge client (spec 3-04) is bound.
 */
final class UsageSourceUnavailableException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Pixelforge usage source is not configured.');
    }
}
