<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a usage record (spec 3-03). An open usage holds max_charge_nanos (D-024).
 */
enum UsageStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Settled = 'settled';
    case Voided = 'voided';

    /** Admin console label (Filament HasLabel), from lang/en/console.php. */
    public function getLabel(): string
    {
        $label = __('console.enums.usage_status.'.$this->value);

        return is_string($label) ? $label : $this->value;
    }

    /** Admin console badge color (Filament HasColor). */
    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Settled => 'success',
            self::Voided => 'gray',
        };
    }
}
