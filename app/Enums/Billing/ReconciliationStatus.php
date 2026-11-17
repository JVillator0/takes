<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * One UTC day of Providers › Pixelforge usage (A04, D-034): our settled usage against `GET /v1/usage`. A day with
 * takes still in flight (or today) is Pending, never Mismatch; Unavailable when Pixelforge could not answer.
 */
enum ReconciliationStatus: string implements HasColor, HasLabel
{
    case Match = 'match';
    case Mismatch = 'mismatch';
    case Pending = 'pending';
    case Unavailable = 'unavailable';

    public function getLabel(): string
    {
        $label = __('console.pixelforge.status.'.$this->value);

        return is_string($label) ? $label : $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Match => 'success',
            self::Mismatch => 'danger',
            self::Pending => 'warning',
            self::Unavailable => 'gray',
        };
    }
}
