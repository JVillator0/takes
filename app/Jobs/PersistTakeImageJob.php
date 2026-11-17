<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Interface stub (D-068, spec 3-02 issue 3): the final name and constructor so
 * ApplyTakeSnapshotAction can dispatch it on success before spec 4 exists.
 */
final class PersistTakeImageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $takeId) {}

    public function handle(): void
    {
        // TODO(spec 4): copy the provider image to the default disk and set takes.image_path (D-020, D-067).
    }
}
