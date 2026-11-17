<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Real-money ledger movements only; holds are state, not rows (D-024).
 */
enum LedgerEntryType: string implements HasColor, HasLabel
{
    case Purchase = 'purchase';
    case PromoGrant = 'promo_grant';
    case Charge = 'charge';
    case Adjustment = 'adjustment';

    /** Admin console label (Filament HasLabel), from lang/en/console.php. */
    public function getLabel(): string
    {
        $label = __('console.enums.ledger_entry_type.'.$this->value);

        return is_string($label) ? $label : $this->value;
    }

    /** Admin console badge color (Filament HasColor). */
    public function getColor(): string
    {
        return match ($this) {
            self::Purchase => 'success',
            self::PromoGrant => 'info',
            self::Charge => 'gray',
            self::Adjustment => 'warning',
        };
    }
}
