<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeStatus;
use App\Events\TakeUpdated;
use App\Models\Take;
use Illuminate\Support\Facades\DB;

/**
 * Records why a pending take is still pending (spec 4 "Submit"):
 * `capacity` (429/503 or our own in-flight gate: nothing was created, so the
 * customer may cancel) or `ambiguous` (timeout, connection error, 5xx: the
 * generation may exist). `ambiguous` is sticky: once a submit is in doubt
 * the take never goes back to `capacity` (D-025), so Cancel never hides a
 * generation Pixelforge may have run. Never touches status (M-11) or money.
 */
final readonly class RecordSubmitOutcomeAction
{
    public function execute(Take $take, SubmitErrorKind $kind, string $error): void
    {
        DB::transaction(function () use ($take, $kind, $error): void {
            $locked = Take::query()->whereKey($take->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== TakeStatus::Pending) {
                return;
            }

            $next = $locked->last_submit_error_kind === SubmitErrorKind::Ambiguous ? SubmitErrorKind::Ambiguous : $kind;
            $changed = $locked->last_submit_error_kind !== $next || $locked->last_submit_error !== $error;

            $locked->forceFill([
                'last_submit_error_kind' => $next,
                'last_submit_error' => $error,
            ])->save();

            $take->setRawAttributes($locked->getAttributes(), sync: true);

            // The card moves between Submitting and Waiting for capacity (2-01).
            if ($changed) {
                DB::afterCommit(fn () => TakeUpdated::dispatch($locked));
            }
        });
    }
}
