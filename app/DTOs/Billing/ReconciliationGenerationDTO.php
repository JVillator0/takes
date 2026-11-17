<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\Nanodollars;

/**
 * One row of the A04 **Find takes** drill-down: a Pixelforge generation completed on the mismatching day, the
 * take it belongs to (when we have one), Pixelforge's cost (tokens × its rates) and ours (the settled usage).
 *
 * Output-only (built by a Query from typed values, never from an array): no `fromArray` (architecture.md).
 */
final readonly class ReconciliationGenerationDTO
{
    public function __construct(
        public string $generationId,
        public ?string $takeId,
        public ?Nanodollars $theirCost,
        public ?Nanodollars $ourCost,
    ) {}
}
