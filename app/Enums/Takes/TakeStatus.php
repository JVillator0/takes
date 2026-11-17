<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * The take state machine (spec 3-02).
 *
 *   pending    → queued | processing | succeeded | failed
 *   queued     → processing | succeeded | failed
 *   processing → succeeded | failed
 *   succeeded, failed → terminal
 *
 * Forward jumps are allowed: a `succeeded` webhook may arrive before
 * `processing` or before the create response. Transitions happen only in
 * ApplyTakeSnapshotAction and FailTakeAction.
 *
 * label()/color() follow Filament's HasLabel/HasColor shape so the interfaces
 * can be added when Filament is installed (M7, D-048).
 */
enum TakeStatus: string
{
    /** Row exists locally, not yet accepted by Pixelforge. */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * States this one may move to.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Queued, self::Processing, self::Succeeded, self::Failed],
            self::Queued => [self::Processing, self::Succeeded, self::Failed],
            self::Processing => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed => [],
        };
    }

    public function canBecome(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Statuses still waiting on the provider (the partial index on `takes`).
     *
     * @return list<self>
     */
    public static function nonTerminal(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => ! $status->isTerminal()));
    }

    /** Admin label. Customer-facing copy comes from 2-01 / lang files (D-057). */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    /** Filament color name. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Queued => 'info',
            self::Processing => 'warning',
            self::Succeeded => 'success',
            self::Failed => 'danger',
        };
    }
}
