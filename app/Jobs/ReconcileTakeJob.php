<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Interface stub (D-068, spec 3-02 issue 3): the final name and constructor so
 * ApplyTakeSnapshotAction can dispatch it before spec 5 exists.
 */
final class ReconcileTakeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $takeId) {}

    public function handle(): void
    {
        // TODO(spec 5): fetch the generation from Pixelforge, apply the snapshot and re-chain while non-terminal.
    }
}
