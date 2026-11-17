<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeStatus;
use App\Enums\Takes\TransitionOutcome;
use App\Exceptions\Takes\TakeCannotBeCancelledException;
use App\Models\Take;
use App\Support\Pixelforge\SubmitGate;
use Illuminate\Support\Facades\DB;

/**
 * The customer cancels a take that is waiting for capacity (spec 4
 * "Cancel", D-044): `failed` with `cancelled`, the hold released, nothing
 * charged. Only while nothing can exist at Pixelforge: the take is
 * `pending`, its last answer was a capacity one, and no create call is in
 * flight (the submit gate holds no slot for it). Under the take lock, so a
 * submit attempt (RecordSubmitAttemptAction takes the same lock) either
 * runs before and blocks the cancel, or after and finds the take failed.
 */
final readonly class CancelTakeAction
{
    public function __construct(
        private FailTakeAction $failTake,
        private SubmitGate $gate,
    ) {}

    /**
     * @throws TakeCannotBeCancelledException when the take is not waiting for capacity
     */
    public function execute(Take $take): TransitionOutcome
    {
        return DB::transaction(function () use ($take): TransitionOutcome {
            Take::query()->whereKey($take->id)->lockForUpdate()->firstOrFail();
            $take->refresh();

            if ($take->status !== TakeStatus::Pending
                || $take->last_submit_error_kind !== SubmitErrorKind::Capacity
                || $take->pixelforge_id !== null
                || $this->gate->isSubmitting($take)) {
                throw TakeCannotBeCancelledException::for($take);
            }

            // Voids the usage (releases the hold) and broadcasts TakeUpdated.
            return $this->failTake->execute($take, FailureCode::Cancelled, 'Cancelled by the customer while waiting for capacity.');
        });
    }
}
