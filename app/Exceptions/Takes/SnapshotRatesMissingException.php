<?php

declare(strict_types=1);

namespace App\Exceptions\Takes;

use RuntimeException;

/**
 * ApplyTakeSnapshotAction was about to settle a take from a `succeeded`
 * snapshot that carries no `rates`. A take is never settled without the
 * generation's own rates (D-055), so nothing was written: the caller fetches
 * the generation (GET /v1/generations/{id}, outside any transaction) and
 * applies that snapshot instead (spec 3-04, "If rates is ever missing,
 * settlement fetches the generation first").
 */
final class SnapshotRatesMissingException extends RuntimeException
{
    public function __construct(
        public readonly string $takeId,
        public readonly string $generationId,
    ) {
        parent::__construct("The succeeded snapshot of {$generationId} (take {$takeId}) has no rates; fetch the generation and apply that snapshot.");
    }
}
