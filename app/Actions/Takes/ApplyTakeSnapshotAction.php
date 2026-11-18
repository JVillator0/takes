<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Actions\Billing\SettleUsageAction;
use App\Actions\Billing\VoidUsageAction;
use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Billing\UsageUnitsDTO;
use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\Enums\Billing\UsageFlag;
use App\Enums\Billing\UsageKind;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\TakeStatus;
use App\Enums\Takes\TransitionOutcome;
use App\Events\TakeUpdated;
use App\Exceptions\Takes\SnapshotRatesMissingException;
use App\Jobs\PersistTakeImageJob;
use App\Jobs\ReconcileTakeJob;
use App\Models\Take;
use App\Support\Money\TokenCost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function __construct(
        private SettleUsageAction $settleUsage,
        private VoidUsageAction $voidUsage,
        private TokenCost $tokenCost,
    ) {}

    /**
     * @param  bool  $flagUnpricedOrphan  the generation was fetched and still has no rates: record an
     *                                    orphan without its cost instead of throwing (ReconcileTakeAction)
     *
     * @throws SnapshotRatesMissingException when a succeeded snapshot that would settle, or price an orphan, has no rates; nothing is written
     */
    public function execute(Take $take, GenerationSnapshotDTO $snapshot, bool $flagUnpricedOrphan = false): TransitionOutcome
    {
        try {
            return $this->apply($take, $snapshot, $flagUnpricedOrphan);
        } catch (Throwable $exception) {
            // The rollback undoes the database, not the caller's model: never
            // hand back a take that still carries an unsaved transition.
            $take->discardChanges();

            throw $exception;
        }
    }

    private function apply(Take $take, GenerationSnapshotDTO $snapshot, bool $flagUnpricedOrphan): TransitionOutcome
    {
        return DB::transaction(function () use ($take, $snapshot, $flagUnpricedOrphan): TransitionOutcome {
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
                $this->flag($take, AttentionReason::InvalidSnapshot);
                $take->save();
                $this->afterCommit(TransitionOutcome::InvalidSnapshot, $take, $snapshot, $from, $invalidReason);

                return TransitionOutcome::InvalidSnapshot;
            }

            $outcome = $this->decide($take, $snapshot);

            // Never price without the generation's own rates (D-055, D-077):
            // neither a settlement nor an orphan's absorbed cost not yet
            // recorded (#102). Checked before any write: the caller fetches the
            // generation (GET /v1/generations/{id}, outside any transaction)
            // and applies that. An orphan may still be recorded without its
            // cost: when the caller asks (the webhook's signed snapshot, or a
            // fetched generation without rates either), and on a retry once
            // the usage already carries the orphan flag, which is then
            // acknowledged instead of answering 5xx again. A settlement
            // without rates always throws.
            if ($snapshot->status === TakeStatus::Succeeded && $snapshot->rates === null && $this->wouldPrice($take, $outcome)
                && ! ($outcome === TransitionOutcome::OrphanSuccess && ($flagUnpricedOrphan || $this->isFlaggedOrphan($take)))) {
                throw new SnapshotRatesMissingException($take->id, $snapshot->id);
            }

            $firstAccepted = $take->pixelforge_id === null;

            // Missing facts (spec 5 step 2). A terminal take keeps the
            // generation id it had when it finished: failureMayHideAGeneration()
            // reads it as "Pixelforge had accepted it when we failed it", so
            // only the orphan, the one outcome that adopts the late
            // generation, may add it. Otherwise a replay could reclassify.
            if (! $from->isTerminal() || $outcome === TransitionOutcome::OrphanSuccess) {
                $take->forceFill([
                    'pixelforge_id' => $snapshot->id,
                    'started_at' => $take->started_at ?? $snapshot->startedAt,
                ]);
            }

            // Only a snapshot that advances or confirms the state proves the
            // take is alive; a stale or contradicting one must not hide a stuck
            // take from the 15-minute rule (spec 5 step 2, D-053, D-077).
            if ($outcome === TransitionOutcome::Applied || $outcome === TransitionOutcome::IgnoredSameState) {
                $take->forceFill(['last_event_at' => now()]);
            }

            if ($outcome === TransitionOutcome::Applied) {
                $this->advance($take, $snapshot);
            } elseif ($outcome === TransitionOutcome::OrphanSuccess) {
                $this->recordOrphan($take, $snapshot);
            } elseif ($outcome === TransitionOutcome::IgnoredConflict) {
                // A terminal take contradicted by the provider: always flagged (spec 5 matrix).
                $this->flag($take, AttentionReason::InternalFailure);
            }

            $take->save();

            // A new provider id restarts the reconcile chain, unless there is nothing left to poll (spec 5).
            $this->afterCommit($outcome, $take, $snapshot, $from, reconcile: $firstAccepted && ! $take->status->isTerminal());

            return $outcome;
        });
    }

    /** The take's usage already carries the `orphan` flag (recorded, maybe without its cost). */
    private function isFlaggedOrphan(Take $take): bool
    {
        return in_array(UsageFlag::Orphan->value, $take->usageRecord()->firstOrFail()->flags, true);
    }

    /**
     * Whether applying this outcome computes a cost from the snapshot's rates:
     * a settlement, or an orphan whose absorbed cost is not recorded yet (a
     * replay of a priced orphan only re-flags it).
     */
    private function wouldPrice(Take $take, TransitionOutcome $outcome): bool
    {
        return $outcome === TransitionOutcome::Applied
            || ($outcome === TransitionOutcome::OrphanSuccess && $take->usageRecord()->value('absorbed_cost_nanos') === null);
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
            // Terminal → the other terminal: an orphan when our local failure
            // may have hidden a generation Pixelforge really ran.
            $to === TakeStatus::Succeeded && $take->failureMayHideAGeneration() => TransitionOutcome::OrphanSuccess,
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
        // invalidReason() rejected a succeeded snapshot without usage, image
        // or completion time, and apply() threw before any write without rates.
        assert($snapshot->usage !== null && $snapshot->rates !== null && $snapshot->imageUrl !== null && $snapshot->completedAt !== null);

        // Only the rates of this terminal snapshot, never the cached
        // /v1/pricing used for the estimate (D-055, W-14).
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
     * Pixelforge ran a generation we had already failed, free: after the
     * ambiguous submit window (D-025), or after an internal failure of a take
     * it had accepted (D-077) or whose submit was in doubt (D-082). The
     * customer keeps "failed, not charged": the usage stays voided and the
     * cost is absorbed. Both rows carry a durable flag for Needs attention
     * and reconciliation (D-054, D-058).
     *
     * The absorbed cost (units × the generation's own rates, on its UTC
     * billing day) is kept on the voided usage, never in the ledger, so
     * `billing:verify --usage` can attribute that day's Pixelforge cost
     * (issue #102). Recorded once: a replay of the same late success finds it.
     * apply() refused an unpriced orphan snapshot without rates unless the
     * caller asked to flag it anyway or it is already flagged: then the flags
     * are recorded and the cost stays null. Only a snapshot with rates prices
     * it later (the webhook's one-off fetch, or an operator's Reconcile now).
     */
    private function recordOrphan(Take $take, GenerationSnapshotDTO $snapshot): void
    {
        $this->flag($take, AttentionReason::OrphanSuccess);

        $usage = $take->usageRecord()->firstOrFail();
        $changes = [];

        if (! in_array(UsageFlag::Orphan->value, $usage->flags, true)) {
            $changes['flags'] = [...$usage->flags, UsageFlag::Orphan->value];
        }

        if ($usage->absorbed_cost_nanos === null && $snapshot->rates !== null) {
            // invalidReason() rejected a succeeded snapshot without usage or completion time.
            assert($snapshot->usage !== null && $snapshot->completedAt !== null);

            $units = new UsageUnitsDTO($snapshot->usage->toUnits());
            $rates = new RatesSnapshotDTO($snapshot->rates->toNanosPerMillion());

            // The same columns SettleUsageAction fills, so the row is whole.
            foreach (UsageKind::cases() as $kind) {
                $changes[$kind->tokenColumn()] = $units->tokens($kind);
            }

            $changes = [
                ...$changes,
                'units' => $units->tokens,
                'rates' => $rates->toArray(),
                'absorbed_cost_nanos' => $this->tokenCost->compute($units->tokens, $rates->toArray(), ['usage_record_id' => $usage->id])->toInt(),
                'external_id' => $snapshot->id,
                'completed_at' => $snapshot->completedAt->utc(),
                'billing_date' => $snapshot->completedAt->utc()->toDateString(),
            ];
        }

        // Not a money write: the usage is voided (cost and charge stay 0), so
        // Settle/Void ignore it, and the take lock serializes every writer of
        // this take's usage.
        if ($changes !== []) {
            $usage->forceFill($changes)->save();
        }
    }

    /**
     * Sets the durable Needs attention flag (D-054) unless it would hide an
     * orphan success: that one carries absorbed provider cost (D-058).
     */
    private function flag(Take $take, AttentionReason $reason): void
    {
        if ($reason->replaces($take->attention_reason)) {
            $take->forceFill(['attention_reason' => $reason]);
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
                // The chain's first delay has one definition (ReconcileTakeJob::DELAYS).
                ReconcileTakeJob::start($take->id);
            }
        });
    }
}
