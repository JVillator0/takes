<?php

declare(strict_types=1);

namespace App\Contracts\Billing;

use App\Exceptions\Billing\UsageSourceUnavailableException;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;

/**
 * Pixelforge's own per-day cost (`GET /v1/usage`), the outside view that
 * `billing:verify --usage` compares the ledger with (D-059). The Pixelforge
 * client (spec 3-04) binds the real implementation; this one method is the
 * whole coupling between billing and that client.
 */
interface PixelforgeUsageSource
{
    /**
     * Provider cost per UTC billing day, inclusive range. Days without usage
     * may be omitted.
     *
     * @return array<string, Nanodollars> keyed by 'Y-m-d'
     *
     * @throws UsageSourceUnavailableException
     */
    public function dailyCosts(CarbonImmutable $from, CarbonImmutable $to): array;
}
