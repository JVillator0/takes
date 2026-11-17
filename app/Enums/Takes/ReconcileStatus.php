<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * What one reconcile poll found (spec 5, W-15). ReconcileTakeJob decides from
 * it whether the chain goes on.
 */
enum ReconcileStatus: string
{
    /** The take is succeeded or failed: nothing left to poll. */
    case Terminal = 'terminal';

    /** Pixelforge still has it queued or processing. */
    case InFlight = 'in_flight';

    /** Pending with no `pixelforge_id`: the submit job owns it, not reconcile. */
    case NotSubmitted = 'not_submitted';

    /** 404: Pixelforge has no such generation; flagged `reconcile_exhausted`. */
    case NotFound = 'not_found';

    /** 401: the key was revoked; the take failed free (D-062). */
    case Revoked = 'revoked';

    /** 429/503: poll again after Retry-After. */
    case Throttled = 'throttled';

    /** Connection error, timeout or 5xx: poll again on the normal schedule. */
    case Unavailable = 'unavailable';

    /** The fetched generation could not be applied; flagged `invalid_snapshot`. */
    case Invalid = 'invalid';

    /** No API key in this environment: nothing can be polled. */
    case NotConfigured = 'not_configured';

    /** Whether the chain should poll this take again. */
    public function pollAgain(): bool
    {
        return $this === self::InFlight || $this === self::Throttled || $this === self::Unavailable;
    }
}
