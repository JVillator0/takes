<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * What applying a provider snapshot or a local failure did to a take (D-049, spec 3-02).
 *
 * ignored_same_state: same status from a new event id.
 * ignored_stale: backwards (e.g. processing after succeeded).
 * ignored_conflict: terminal to a different terminal, or a late success after a definite local failure.
 * orphan_success: a success after a local submit_timeout, or after a local internal failure of a
 *   take Pixelforge had accepted (D-077); usage stays voided, cost absorbed.
 * invalid_snapshot: the payload failed validation; no state change.
 */
enum TransitionOutcome: string
{
    case Applied = 'applied';
    case IgnoredSameState = 'ignored_same_state';
    case IgnoredStale = 'ignored_stale';
    case IgnoredConflict = 'ignored_conflict';
    case OrphanSuccess = 'orphan_success';
    case InvalidSnapshot = 'invalid_snapshot';

    /**
     * PSR-3 level for the transition log line: a conflict or an orphan is a
     * warning (spec 5 matrix), an invalid snapshot is critical (W-13).
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::Applied, self::IgnoredSameState, self::IgnoredStale => 'info',
            self::IgnoredConflict, self::OrphanSuccess => 'warning',
            self::InvalidSnapshot => 'critical',
        };
    }
}
