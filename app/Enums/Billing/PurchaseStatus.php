<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Status of a simulated credit purchase (spec 3-03 §5, D-051).
 */
enum PurchaseStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
}
