<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Actions\Billing\VoidUsageAction;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeStatus;
use App\Enums\Takes\TransitionOutcome;
use App\Events\TakeUpdated;
use App\Exceptions\Takes\TakeTransitionNotAllowedException;
use App\Models\Take;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fails a take for a local reason, free (spec 3-02): a definite rejection at
 * submit (content_policy, daily_limit, validation, internal), a cancel while
 * waiting for capacity (D-044), the end of the 30-minute submit window
 * (provider_busy, submit_timeout; D-025), or `internal` on a take Pixelforge
 * already accepted (a revoked key while reconciling, D-062). With ApplyTakeSnapshotAction, the
 * only code that changes a take's status (M-11); the usage is voided in the
 * same transaction, so the hold is released exactly once (M-12, M-13).
 */
final readonly class FailTakeAction
{
    public function __construct(private VoidUsageAction $voidUsage) {}

    /**
     * @throws TakeTransitionNotAllowedException for a provider-only code, or a cancel of any take that is not pending and waiting for capacity (already failed, succeeded, accepted by Pixelforge, or with an ambiguous submit in doubt); nothing is written
     */
    public function execute(Take $take, FailureCode $code, string $message): TransitionOutcome
    {
        if ($code === FailureCode::GenerationFailed || $code === FailureCode::InternalError) {
            throw TakeTransitionNotAllowedException::providerCode($code);
        }

        try {
            return $this->fail($take, $code, $message);
        } catch (Throwable $exception) {
            // The rollback undoes the database, not the caller's model: never
            // hand back a take that still carries an unsaved transition.
            $take->discardChanges();

            throw $exception;
        }
    }

    private function fail(Take $take, FailureCode $code, string $message): TransitionOutcome
    {
        return DB::transaction(function () use ($take, $code, $message): TransitionOutcome {
            // Lock order: the take first; VoidUsageAction then locks only the customer (spec 3-03 §6).
            Take::query()->whereKey($take->id)->lockForUpdate()->firstOrFail();
            $take->refresh();

            $from = $take->status;

            $outcome = match (true) {
                $from === TakeStatus::Failed => TransitionOutcome::IgnoredSameState,
                // Terminal → a different terminal (spec 3-02).
                $from === TakeStatus::Succeeded => TransitionOutcome::IgnoredConflict,
                // Pixelforge accepted the take: its snapshots decide now. Only
                // `internal` (a revoked key while reconciling, D-062) still fails it.
                $from !== TakeStatus::Pending && $code !== FailureCode::Internal => TransitionOutcome::IgnoredStale,
                default => TransitionOutcome::Applied,
            };

            // Cancel only while nothing can exist at Pixelforge (D-044): with an
            // ambiguous submit in doubt, a cancel could hide a real generation.
            // Any other cancel is the customer's race with the take, not a
            // finding: one answer (CancelTakeAction's 422), no attention flag.
            if ($code === FailureCode::Cancelled
                && ($outcome !== TransitionOutcome::Applied || $take->last_submit_error_kind !== SubmitErrorKind::Capacity)) {
                throw TakeTransitionNotAllowedException::cancel($take);
            }

            if ($outcome === TransitionOutcome::Applied) {
                // Failed = free: release the hold, charge nothing (D-024, M-13).
                // First, so a failure here leaves the caller's model untouched.
                $this->voidUsage->execute($take->usageRecord()->firstOrFail());

                $now = now();

                $take->forceFill([
                    'status' => TakeStatus::Failed,
                    'failure_code' => $code,
                    'failure_message' => $message,
                    // Local failures use local time (spec 3-02).
                    'completed_at' => $now,
                    'last_event_at' => $now,
                ]);

                // A configuration or code problem, never the customer's (D-054, D-062).
                if ($code === FailureCode::Internal && AttentionReason::InternalFailure->replaces($take->attention_reason)) {
                    $take->forceFill(['attention_reason' => AttentionReason::InternalFailure]);
                }

                $take->save();
            } elseif ($outcome === TransitionOutcome::IgnoredConflict && AttentionReason::InternalFailure->replaces($take->attention_reason)) {
                $take->forceFill(['attention_reason' => AttentionReason::InternalFailure])->save();
            }

            $context = [
                'take_id' => $take->id,
                'failure_code' => $code->value,
                'from' => $from->value,
                'to' => $take->status->value,
                'outcome' => $outcome->value,
            ];

            // Log and broadcast only once the outermost transaction commits.
            DB::afterCommit(function () use ($outcome, $take, $context): void {
                Log::log($outcome->logLevel(), 'takes.local_failure', $context);

                if ($outcome === TransitionOutcome::Applied) {
                    TakeUpdated::dispatch($take);
                }
            });

            return $outcome;
        });
    }
}
