<?php

declare(strict_types=1);

namespace App\DTOs\Takes;

/**
 * Header stats of Studio › Takes (A09): takes in progress now, stuck takes (D-053), the failure rate of takes
 * created in the last 30 days (failed ÷ terminal, in basis points, null with no terminal take) and the median
 * duration (`completed_at − submitted_at`, interpolated median of an even count, floored) of succeeded takes in
 * that range, in whole seconds.
 */
final readonly class TakeStatsDTO
{
    public function __construct(
        public int $inProgress,
        public int $stuck,
        public ?int $failureRateBps,
        public ?int $medianDurationSeconds,
    ) {}

    /**
     * @param  array{in_progress: int, stuck: int, failure_rate_bps: int|null, median_duration_seconds: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['in_progress'], $data['stuck'], $data['failure_rate_bps'], $data['median_duration_seconds']);
    }
}
