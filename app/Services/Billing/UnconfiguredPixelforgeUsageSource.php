<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Billing\PixelforgeUsageSource;
use App\Exceptions\Billing\UsageSourceUnavailableException;
use Carbon\CarbonImmutable;

/**
 * Bound until the Pixelforge client (spec 3-04) provides the real source:
 * `billing:verify --usage` then reports "not configured" instead of
 * pretending the comparison passed.
 */
final class UnconfiguredPixelforgeUsageSource implements PixelforgeUsageSource
{
    public function dailyCosts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        throw UsageSourceUnavailableException::notConfigured();
    }
}
