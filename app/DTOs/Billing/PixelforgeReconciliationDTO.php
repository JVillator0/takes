<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use Carbon\CarbonImmutable;

/**
 * Providers › Pixelforge usage result (A04): newest day first, the totals of both sides, "N / M days match" over
 * the days that can be judged (not pending), when Pixelforge's figures were fetched, and whether this answer
 * fell back to cached figures because Pixelforge could not be reached.
 *
 * Output-only (built by a Query from typed values, never from an array): no `fromArray` (architecture.md).
 */
final readonly class PixelforgeReconciliationDTO
{
    /**
     * @param  list<ReconciliationDayDTO>  $days
     */
    public function __construct(
        public array $days,
        public UsageTotalsDTO $oursTotal,
        public ?UsageTotalsDTO $theirsTotal,
        public int $matchedDays,
        public int $judgedDays,
        public ?CarbonImmutable $fetchedAt,
        public bool $unavailable,
    ) {}
}
