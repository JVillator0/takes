<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Real-money ledger movements only; holds are state, not rows (D-024).
 */
enum LedgerEntryType: string
{
    case Purchase = 'purchase';
    case PromoGrant = 'promo_grant';
    case Charge = 'charge';
    case Adjustment = 'adjustment';
}
