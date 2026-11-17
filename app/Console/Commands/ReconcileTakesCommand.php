<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Takes\ReconcileTakeAction;
use App\Actions\Takes\SweepStaleTakesAction;
use App\Models\Take;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Manual entry to the safety net (spec 5): reconcile one take now, or sweep
 * every stale take and suggestion without waiting for the throttle.
 */
#[Signature('takes:reconcile {--take= : Reconcile this take id now} {--stale : Sweep stale takes and suggestions}')]
#[Description('Reconcile takes with Pixelforge (one take now, or every stale one)')]
final class ReconcileTakesCommand extends Command
{
    public function handle(ReconcileTakeAction $reconcile, SweepStaleTakesAction $sweep): int
    {
        $takeId = $this->option('take');

        if (is_string($takeId) && $takeId !== '') {
            $take = Take::query()->find($takeId);

            if ($take === null) {
                $this->error("No take {$takeId}.");

                return self::FAILURE;
            }

            $result = $reconcile->execute($take);
            $this->info("Take {$take->id}: {$result->status->value}, now {$take->status->value}.");

            return self::SUCCESS;
        }

        if ($this->option('stale') === true) {
            $result = $sweep->execute(force: true);
            $this->info("Queued {$result->reconcilesDispatched} reconcile polls, expired {$result->suggestionsExpired} suggestions.");

            return self::SUCCESS;
        }

        $this->error('Pass --take=<id> or --stale.');

        return self::INVALID;
    }
}
