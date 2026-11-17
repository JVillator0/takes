<?php

declare(strict_types=1);

/*
 * Applies the docs' succeeded snapshot to one take in its own PHP process,
 * for TakeTransitionConcurrencyTest (spec 3-02 issue 3). Same shape as
 * worker.php: boot against the test database from the environment, wait for
 * a shared start time, run inside a transaction and keep it (and its locks)
 * open for `holdMs` so the other worker has to wait on the take row.
 *
 *   php tests/Committed/apply-worker.php <takeId> <startAt> <holdMs>
 *
 * Prints the TransitionOutcome value.
 */

use App\Actions\Takes\ApplyTakeSnapshotAction;
use App\Enums\Takes\TransitionOutcome;
use App\Models\Take;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Takes;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $takeId, $startAt, $holdMs] = $argv;

$take = Take::query()->findOrFail($takeId);
$snapshot = Takes::snapshot($take, 'succeeded');

// A slow boot may pass the start line; time_sleep_until() warns on a past time.
if ((float) $startAt > microtime(true)) {
    time_sleep_until((float) $startAt);
}

$outcome = DB::transaction(function () use ($take, $snapshot, $holdMs): TransitionOutcome {
    $outcome = app(ApplyTakeSnapshotAction::class)->execute($take, $snapshot);

    usleep((int) $holdMs * 1000);

    return $outcome;
});

echo $outcome->value;
