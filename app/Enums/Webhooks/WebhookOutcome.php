<?php

declare(strict_types=1);

namespace App\Enums\Webhooks;

use App\Enums\Takes\TransitionOutcome;

/**
 * Outcome of one verified webhook delivery (D-027, D-049): every
 * TransitionOutcome plus the delivery-level ones. Stored only on
 * `webhook_deliveries.outcome`. A bad signature writes nothing (D-056), so
 * there is no `invalid_signature` case.
 */
enum WebhookOutcome: string
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
}
