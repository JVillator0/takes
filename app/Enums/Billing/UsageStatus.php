<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Lifecycle of a usage record (spec 3-03). An open usage holds max_charge_nanos (D-024).
 */
enum UsageStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
    case Voided = 'voided';
}
