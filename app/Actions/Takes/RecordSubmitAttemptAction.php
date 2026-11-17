<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Enums\Takes\TakeStatus;
use App\Models\Take;
use Illuminate\Support\Facades\DB;

/**
 * Counts one createGeneration attempt just before it is sent (spec 4
 * "Submit"), under the take lock, so a cancel and an attempt never overlap:
 * once a cancel has failed the take, this answers false and the submit job
 * never calls Pixelforge. The lock is released before the HTTP call (M-16).
 * Never touches status (M-11) or money.
 */
final readonly class RecordSubmitAttemptAction
{
    /**
     * @return bool false when the take is no longer waiting to be submitted
     */
    public function execute(Take $take): bool
    {
        return DB::transaction(function () use ($take): bool {
            $locked = Take::query()->whereKey($take->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== TakeStatus::Pending || $locked->pixelforge_id !== null) {
                return false;
            }

            $locked->forceFill([
                'submit_attempts' => $locked->submit_attempts + 1,
                'submitted_at' => $locked->submitted_at ?? now(),
            ])->save();

            $take->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });
    }
}
