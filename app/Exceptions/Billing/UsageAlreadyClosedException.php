<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Models\UsageRecord;
use LogicException;

/**
 * OpenUsageAction found the billable's usage already settled or voided: its
 * provider call is finished, and a new call would run without a hold.
 */
final class UsageAlreadyClosedException extends LogicException
{
    public static function for(UsageRecord $usage): self
    {
        return new self("Usage {$usage->id} is already {$usage->status->value}; it cannot be opened again.");
    }
}
