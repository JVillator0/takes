<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\Billing\ReconciliationStatus;

/**
 * One row of Providers › Pixelforge usage (A04): the UTC day, our settled totals, Pixelforge's (null when it
 * could not be reached), takes still in flight that day, and the resulting status.
 */
final readonly class ReconciliationDayDTO
{
    public function __construct(
        public string $date,
        public UsageTotalsDTO $ours,
        public ?UsageTotalsDTO $theirs,
        public int $inFlight,
        public ReconciliationStatus $status,
    ) {}
}
