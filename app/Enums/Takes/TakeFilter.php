<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * The workspace grid filter (2-02). In progress groups Submitting, Waiting
 * for capacity, Queued and Processing; the same definition drives the
 * counts, the P03 badge and the "on hold" link.
 */
enum TakeFilter: string
{
    case All = 'all';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Failed = 'failed';

    /**
     * @return list<string> the take statuses this filter shows
     */
    public function statuses(): array
    {
        return match ($this) {
            self::All => array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::cases()),
            self::InProgress => array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal()),
            self::Done => [TakeStatus::Succeeded->value],
            self::Failed => [TakeStatus::Failed->value],
        };
    }
}
