<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Actions\Billing\SettleUsageAction;
use App\Actions\Billing\VoidUsageAction;
use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Billing\UsageUnitsDTO;
use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\Enums\Billing\UsageFlag;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\TakeStatus;
use App\Enums\Takes\TransitionOutcome;
use App\Events\TakeUpdated;
use App\Exceptions\Takes\SnapshotRatesMissingException;
use App\Jobs\PersistTakeImageJob;
use App\Jobs\ReconcileTakeJob;
use App\Models\Take;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies what Pixelforge says about a generation (create response, webhook,
 * reconcile, admin "Reconcile now") to its take. With FailTakeAction, the only
 * code that changes a take's status (spec 3-02, M-11), and the place where a
 * take's money settles exactly once: entering `succeeded` settles the usage,
 * entering `failed` voids it, in the transaction that moves the take (M-12).
 * A replay finds the take already terminal and never reaches the money.
 *
 * No HTTP happens here (M-16). Callers add their own log context (event id,
 * delivery attempt) with Log::withContext(); this action logs the take, the
 * generation, the statuses and the outcome, never the signed image URL.
 */
final readonly class ApplyTakeSnapshotAction
{
    /** First poll of the reconcile chain once Pixelforge accepts the take (spec 5: 60, 120, 300, 600, 840 s). */
    private const int FIRST_RECONCILE_DELAY_SECONDS = 60;

    public function __construct(
        private SettleUsageAction $settleUsage,
        private VoidUsageAction $voidUsage,
    ) {}

    /**
     * @throws SnapshotRatesMissingException when a succeeded snapshot that would settle has no rates; nothing is written
     */
    public function execute(Take $take, GenerationSnapshotDTO $snapshot): TransitionOutcome
    {
        return DB::transaction(function () use ($take, $snapshot): TransitionOutcome {
            // Lock order: the take first; Settle/Void then lock only the
            // customer (spec 3-03 §6). Everything below decides on the row as
            // it is under this lock, never on the caller's copy.
            Take::query()->whereKey($take->id)->lockForUpdate()->firstOrFail();
            $take->refresh();

            $from = $take->status;
            $invalidReason = $this->invalidReason($take, $snapshot);

            if ($invalidReason !== null) {
                // No state change, no last_event_at: a snapshot we cannot trust
                // must not make the take look alive (D-053). Durable flag (D-054).
                $take->forceFill(['attention_reason' => AttentionReason::InvalidSnapshot])->save();
                $this->afterCommit(TransitionOutcome::InvalidSnapshot, $take, $snapshot, $from, $invalidReason);

                return TransitionOutcome::InvalidSnapshot;
            }

            $outcome = $this->decide($take, $snapshot);
            $firstAccepted = $take->pixelforge_id === null;

            // Facts every valid snapshot may fill, whatever the outcome (spec 5 step 2).
            $take->forceFill([
                'pixelforge_id' => $snapshot->id,
                'started_at' => $take->started_at ?? $snapshot->startedAt,
                'last_event_at' => now(),
            ]);

            if ($outcome === TransitionOutcome::Applied) {
                $this->advance($take, $snapshot);
            } elseif ($outcome === TransitionOutcome::OrphanSuccess) {
                $this->recordOrphan($take);
            } elseif ($outcome === TransitionOutcome::IgnoredConflict) {
                // A terminal take contradicted by the provider: always flagged (spec 5 matrix).
                $take->forceFill(['attention_reason' => AttentionReason::InternalFailure]);
            }

            $take->save();

            // A new provider id restarts the reconcile chain, unless there is nothing left to poll (spec 5).
            $this->afterCommit($outcome, $take, $snapshot, $from, reconcile: $firstAccepted && ! $take->status->isTerminal());

            return $outcome;
        });
    }

    /**
     * Why this snapshot cannot be applied to this take, or null when it can.
     * GenerationSnapshotDTO::fromArray() already rejects a malformed payload;
     * this guards every caller, and is the only place that knows the take
     * (spec 5 step 3, W-13).
     */
    private function invalidReason(Take $take, GenerationSnapshotDTO $snapshot): ?string
    {
        $appTakeId = $snapshot->appTakeId();

        return match (true) {
            $appTakeId !== null && $appTakeId !== $take->id => 'metadata.app_take_id names another take',
            $take->pixelforge_id !== null && $take->pixelforge_id !== $snapshot->id => 'the generation id differs from the take pixelforge_id',
            // We never adopt a generation that nothing ties to this take.
            $take->pixelforge_id === null && $appTakeId === null => 'no pixelforge_id or metadata.app_take_id ties the generation to the take',
            $snapshot->status === TakeStatus::Succeeded && ($snapshot->usage === null || $snapshot->imageUrl === null) => 'succeeded without usage or image_url',
            $snapshot->status === TakeStatus::Failed && $snapshot->errorCode === null => 'failed without an error',
            $snapshot->isTerminal() && $snapshot->completedAt === null => 'finished without completed_at',
            default => null,
        };
    }

    /**
     * The spec 5 outcome matrix. Forward jumps are allowed: a `succeeded`
     * may arrive before `processing` or before the create response.
     */
    private function decide(Take $take, GenerationSnapshotDTO $snapshot): TransitionOutcome
    {
        $from = $take->status;
        $to = $snapshot->status;

        return match (true) {
            $to === $from => TransitionOutcome::IgnoredSameState,
            $from->canBecome($to) => TransitionOutcome::Applied,
            // Backwards: processing after succeeded, queued after processing.
            ! $to->isTerminal() => TransitionOutcome::IgnoredStale,
            // Terminal → the other terminal. Only an expired ambiguous submit
            // can hide a generation Pixelforge really ran (D-025, D-049).
            $to === TakeStatus::Succeeded && $take->failure_code === FailureCode::SubmitTimeout => TransitionOutcome::OrphanSuccess,
            default => TransitionOutcome::IgnoredConflict,
        };
    }

    /**
     * Moves the take forward. Entering a terminal state settles or voids the
     * usage in this same transaction, so it happens exactly once (M-12).
     */
    private function advance(Take $take, GenerationSnapshotDTO $snapshot): void
    {
        $take->forceFill(['status' => $snapshot->status]);

        if ($snapshot->status === TakeStatus::Succeeded) {
            $this->settle($take, $snapshot);
        } elseif ($snapshot->status === TakeStatus::Failed) {
            $this->fail($take, $snapshot);
        }
    }

    private function settle(Take $take, GenerationSnapshotDTO $snapshot): void
    {
        // Only the rates of this terminal snapshot, never the cached
        // /v1/pricing used for the estimate (D-055, W-14). Nothing is written
        // yet, so throwing leaves the take untouched for the caller to refetch.
        if ($snapshot->rates === null) {
            throw new SnapshotRatesMissingException($take->id, $snapshot->id);
        }

        // invalidReason() rejected a succeeded snapshot without these.
        assert($snapshot->usage !== null && $snapshot->imageUrl !== null && $snapshot->completedAt !== null);

        // Charges cost × 1.25 once and releases the hold; a no-op if the
        // usage is not open (spec 3-03 §6).
        $this->settleUsage->execute(
            usage: $take->usageRecord()->firstOrFail(),
            units: new UsageUnitsDTO($snapshot->usage->toUnits()),
            rates: new RatesSnapshotDTO($snapshot->rates->toNanosPerMillion()),
            completedAt: $snapshot->completedAt,
            externalId: $snapshot->id,
        );

        $take->forceFill([
            'image_url' => $snapshot->imageUrl,
            'image_url_expires_at' => $snapshot->imageUrlExpiresAt,
            // The provider's instant, the same one the usage's billing_date comes from (spec 3-02).
            'completed_at' => $snapshot->completedAt,
        ]);
    }

    private function fail(Take $take, GenerationSnapshotDTO $snapshot): void
    {
        // Failed = free: release the hold, charge nothing (D-024, M-13).
        $this->voidUsage->execute($take->usageRecord()->firstOrFail());

        $take->forceFill([
            // Provider outcomes are generation_failed or internal_error (D-049).
            'failure_code' => $snapshot->errorCode === FailureCode::InternalError->value
                ? FailureCode::InternalError
                : FailureCode::GenerationFailed,
            'failure_message' => $snapshot->errorMessage,
            'completed_at' => $snapshot->completedAt,
        ]);
    }

    /**
     * Pixelforge ran a generation we had already failed, free, after the
     * ambiguous submit window (D-025). The customer keeps "failed, not
     * charged": the usage stays voided and the cost is absorbed. Both rows
     * carry a durable flag for Needs attention and reconciliation (D-054, D-058).
     */
    private function recordOrphan(Take $take): void
    {
        $take->forceFill(['attention_reason' => AttentionReason::OrphanSuccess]);

        $usage = $take->usageRecord()->firstOrFail();

        // Not a money write: the usage is voided, so Settle/Void ignore it,
        // and the take lock serializes every writer of this take's usage.
        if (! in_array(UsageFlag::Orphan->value, $usage->flags, true)) {
            $usage->forceFill(['flags' => [...$usage->flags, UsageFlag::Orphan->value]])->save();
        }
    }

    /**
     * Logs and dispatches once the outermost transaction commits (the webhook
     * handler wraps this action in its own): a rollback leaves no job, no
     * broadcast and no log line about a change that never happened.
     */
    private function afterCommit(
        TransitionOutcome $outcome,
        Take $take,
        GenerationSnapshotDTO $snapshot,
        TakeStatus $from,
        ?string $invalidReason = null,
        bool $reconcile = false,
    ): void {
        $context = array_filter([
            'take_id' => $take->id,
            'generation_id' => $snapshot->id,
            'from' => $from->value,
            'incoming' => $snapshot->status->value,
            'to' => $take->status->value,
            'outcome' => $outcome->value,
            'replayed' => $snapshot->replayed,
            'reason' => $invalidReason,
        ], fn (mixed $value): bool => $value !== null);
        $succeeded = $take->status === TakeStatus::Succeeded;

        DB::afterCommit(function () use ($outcome, $take, $context, $succeeded, $reconcile): void {
            Log::log($outcome->logLevel(), 'takes.snapshot', $context);

            if ($outcome === TransitionOutcome::Applied) {
                TakeUpdated::dispatch($take);

                if ($succeeded) {
                    PersistTakeImageJob::dispatch($take->id);
                }
            }

            if ($reconcile) {
                ReconcileTakeJob::dispatch($take->id)->delay(self::FIRST_RECONCILE_DELAY_SECONDS);
            }
        });
    }
}
