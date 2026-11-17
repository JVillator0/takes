<?php

declare(strict_types=1);

namespace App\Enums\Takes;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a take came from: the composer, a remix of another take, or an AI change (spec 3-02).
 */
enum TakeSource: string implements HasColor, HasLabel
{
    case Composer = 'composer';
    case Remix = 'remix';
    case AiChange = 'ai_change';

    /** Admin console label (Filament HasLabel), from lang/en/console.php. */
    public function getLabel(): string
    {
        $label = __('console.enums.take_source.'.$this->value);

        return is_string($label) ? $label : $this->value;
    }

    /** Admin console badge color (Filament HasColor). */
    public function getColor(): string
    {
        return match ($this) {
            self::Composer => 'gray',
            self::Remix => 'info',
            self::AiChange => 'primary',
        };
    }
}
