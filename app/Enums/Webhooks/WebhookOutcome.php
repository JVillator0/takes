<?php

declare(strict_types=1);

namespace App\Enums\Webhooks;

use App\Enums\Takes\TransitionOutcome;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Outcome of one verified webhook delivery (D-027, D-049): every
 * TransitionOutcome plus the delivery-level ones. Stored only on
 * `webhook_deliveries.outcome`. A bad signature writes nothing (D-056), so
 * there is no `invalid_signature` case.
 */
enum WebhookOutcome: string implements HasColor, HasLabel
{
    case Applied = 'applied';
    case IgnoredSameState = 'ignored_same_state';
    case IgnoredStale = 'ignored_stale';
    case IgnoredConflict = 'ignored_conflict';
    case OrphanSuccess = 'orphan_success';
    case InvalidSnapshot = 'invalid_snapshot';

    /** Same event id seen before. */
    case Duplicate = 'duplicate';
    case Ping = 'ping';

    /** No take matches the generation id or metadata. */
    case UnknownTake = 'unknown_take';

    /** An event type we do not act on. */
    case IgnoredType = 'ignored_type';

    public static function fromTransition(TransitionOutcome $outcome): self
    {
        return self::from($outcome->value);
    }

    /** Admin console label (Filament HasLabel), from lang/en/console.php. */
    public function getLabel(): string
    {
        $label = __('console.enums.webhook_outcome.'.$this->value);

        return is_string($label) ? $label : $this->value;
    }

    /** Admin console badge color (Filament HasColor). */
    public function getColor(): string
    {
        return match ($this) {
            self::Applied, self::Ping => 'success',
            self::Duplicate, self::IgnoredSameState, self::IgnoredType => 'gray',
            self::IgnoredStale, self::IgnoredConflict => 'warning',
            self::OrphanSuccess, self::InvalidSnapshot, self::UnknownTake => 'danger',
        };
    }
}
