<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Support\Money\Nanodollars;
use RuntimeException;

/**
 * Thrown by OpenUsageAction when, under the customer lock, the available
 * credit (balance − held, D-024) does not cover the hold. The policy check
 * is UX; this is the guarantee (spec 3-01, D-015).
 */
final class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $customerId,
        public readonly Nanodollars $available,
        public readonly Nanodollars $required,
    ) {
        parent::__construct(sprintf(
            'Customer %d has %s available but %s is required.',
            $customerId,
            $available->formatExact(),
            $required->formatExact(),
        ));
    }
}
