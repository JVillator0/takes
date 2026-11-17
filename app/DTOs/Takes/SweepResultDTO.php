<?php

declare(strict_types=1);

namespace App\DTOs\Takes;

/**
 * What one sweep did (D-036): whether it ran at all (the throttle lock), how
 * many reconcile polls it queued and how many stale suggestions it expired.
 */
final readonly class SweepResultDTO
{
    public function __construct(
        public bool $ran,
        public int $reconcilesDispatched = 0,
        public int $suggestionsExpired = 0,
    ) {}

    /**
     * @param  array{ran: bool, reconciles_dispatched?: int, suggestions_expired?: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ran: $data['ran'],
            reconcilesDispatched: $data['reconciles_dispatched'] ?? 0,
            suggestionsExpired: $data['suggestions_expired'] ?? 0,
        );
    }

    public static function skipped(): self
    {
        return new self(ran: false);
    }
}
