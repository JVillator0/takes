<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Takes\SweepStaleTakesAction;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One opportunistic sweep (D-036, W-16), queued by the SweepStaleTakes
 * middleware once the throttle lock is won. It runs as the system, never as
 * the admin whose page load triggered it: the expired suggestions, released
 * holds and queued polls are not that admin's console actions (FI-08, FI-09),
 * so the admin audit must not record them.
 */
final class SweepStaleTakesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(SweepStaleTakesAction $sweep): void
    {
        // On a worker this is already false; with the `sync` driver the job
        // runs in the request's process right after the console response, so
        // leave the console context before writing anything.
        Filament::setServingStatus(false);

        $sweep->execute(force: true);
    }
}
