<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * An invoice is issued for a purchase that is already paid (spec 6, D-022);
 * there is no draft or void state.
 */
enum InvoiceStatus: string
{
    case Paid = 'paid';
}
