<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\DTOs\Takes\ReconcileResultDTO;
use App\Enums\Billing\UsageFlag;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\ReconcileStatus;
use App\Enums\Takes\TransitionOutcome;
use App\Exceptions\Billing\TransactionNotAllowedException;
use App\Exceptions\Pixelforge\PixelforgeBusy;
use App\Exceptions\Pixelforge\PixelforgeRejected;
use App\Exceptions\Pixelforge\PixelforgeUnavailable;
use App\Exceptions\Takes\SnapshotRatesMissingException;
use App\Jobs\SubmitTakeJob;
use App\Models\Take;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Takes\ReconcileStopMarker;
use Illuminate\Support\Facades\Log;

/**
 * One reconcile poll (spec 5, W-15): read the generation from Pixelforge and
 * apply it like a webhook would. The safety net for lost or late webhooks;
 * used by ReconcileTakeJob, `takes:reconcile` and admin "Reconcile now".
 *
 * The GET happens with no transaction open and no row locked (M-16): never
 * call this inside a transaction. The snapshot is then applied by
 * ApplyTakeSnapshotAction in its own transaction.
 */
final readonly class ReconcileTakeAction
{
    public function __construct(
        private PixelforgeClient $client,
        private ApplyTakeSnapshotAction $applySnapshot,
        private FailTakeAction $failTake,
        private FlagTakeAttentionAction $flagAttention,
    ) {}

    /**
     * @param  string|null  $generationId  the generation a webhook named (ReconcileTakeJob::fetchNow):
     *                                     for a take found by metadata before it stored its
     *                                     `pixelforge_id`, or an orphan success that came without rates
     * @param  bool  $operator  one take an operator asked for (admin Reconcile now, `takes:reconcile --take`):
     *                          it may poll a failed take recorded as an orphan without its cost, to price it
     */
    public function execute(Take $take, ?string $generationId = null, bool $operator = false): ReconcileResultDTO
    {
        // The GET below must never run with a row lock held (M-16).
        TransactionNotAllowedException::unlessNoTransaction(self::class);

        $take->refresh();
        $requested = $generationId;
        $generationId = $take->pixelforge_id ?? $generationId;

        // A terminal take is not polled, with two exceptions for a failed
        // take whose orphan cost is not recorded (W-15): the fetch a webhook
        // asked for (an explicit generation id, its late success came without
        // rates), and an operator's single-take reconcile of a take already
        // recorded as an orphan without its cost. A chain poll or the sweeper
        // never re-polls a failed take.
        if ($take->status->isTerminal() && ! $this->pricesUnpricedOrphan($take, $requested, $operator)) {
            return new ReconcileResultDTO(ReconcileStatus::Terminal);
        }

        if ($generationId === null) {
            // Lost jobs (spec 4): submit again with the same idempotency key, so
            // a replay is never a second generation (D-033). SubmitTakeJob is
            // unique per take for the whole window, so this is dropped while
            // the first one can still run.
            Log::info('Reconcile re-dispatched the submit: the take has no generation yet.', ['take_id' => $take->id]);
            SubmitTakeJob::dispatch($take->id);

            return new ReconcileResultDTO(ReconcileStatus::NotSubmitted);
        }

        if (! $this->client->isConfigured()) {
            return new ReconcileResultDTO(ReconcileStatus::NotConfigured);
        }

        try {
            $snapshot = $this->client->getGeneration($generationId);
        } catch (PixelforgeBusy $exception) {
            return new ReconcileResultDTO(ReconcileStatus::Throttled, $exception->retryAfter);
        } catch (PixelforgeUnavailable) {
            return new ReconcileResultDTO(ReconcileStatus::Unavailable);
        } catch (PixelforgeRejected $exception) {
            return $this->rejected($take, $exception);
        }

        try {
            $outcome = $this->applySnapshot->execute($take, $snapshot);
        } catch (SnapshotRatesMissingException) {
            if ($take->failureMayHideAGeneration()) {
                return $this->unpricedOrphan($take, $snapshot);
            }

            // Even the fetched generation has no rates: never settle from
            // cached prices (D-055). Nothing was written; flag it.
            Log::critical('Reconcile cannot settle: the generation has no rates.', ['take_id' => $take->id]);
            $this->flagAttention->execute($take, AttentionReason::InvalidSnapshot);
            ReconcileStopMarker::mark($take->id, ReconcileStatus::Invalid);

            return new ReconcileResultDTO(ReconcileStatus::Invalid);
        }

        // Polling works again: let the sweeper pick the take up if it stalls.
        ReconcileStopMarker::forget($take->id);

        if ($this->isUnpricedOrphan($take)) {
            $this->logUnpricedOrphan($take, $snapshot->id, $outcome);
        }

        Log::info('Reconcile applied a snapshot.', [
            'take_id' => $take->id,
            'generation_id' => $snapshot->id,
            'outcome' => $outcome->value,
        ]);

        return new ReconcileResultDTO($take->refresh()->status->isTerminal()
            ? ReconcileStatus::Terminal
            : ReconcileStatus::InFlight);
    }

    /**
     * Whether this poll may read the generation of a failed take: its local
     * failure may have hidden a generation Pixelforge ran
     * (Take::failureMayHideAGeneration()) and its absorbed cost is not
     * recorded, and either a webhook named the generation (its late success
     * came without rates, ReconcileTakeJob::fetchNow) or an operator asked
     * for this take after the orphan was recorded without its cost (it then
     * has the adopted `pixelforge_id`). Applying the fetched generation
     * records the orphan with its own rates (#102).
     */
    private function pricesUnpricedOrphan(Take $take, ?string $requested, bool $operator): bool
    {
        if (! $take->failureMayHideAGeneration() || $take->usageRecord()->value('absorbed_cost_nanos') !== null) {
            return false;
        }

        return $requested !== null
            || ($operator && $take->pixelforge_id !== null && $this->isUnpricedOrphan($take));
    }

    /** The take is recorded as an orphan success whose absorbed cost is still unknown. */
    private function isUnpricedOrphan(Take $take): bool
    {
        $usage = $take->usageRecord()->first();

        return $usage !== null
            && $usage->absorbed_cost_nanos === null
            && in_array(UsageFlag::Orphan->value, $usage->flags, true);
    }

    /**
     * The fetched generation of a failed take's late success has no rates
     * either: never price it from cached prices (D-055), but record the
     * orphan. The usage gets the `orphan` flag, the take adopts the
     * generation and gets `orphan_success`, so spec 7's orphan group lists
     * it. The absorbed cost stays null and that day's `billing:verify
     * --usage` stays a loud mismatch until an operator's Reconcile now
     * fetches a generation with rates, or the cost is fixed by hand.
     */
    private function unpricedOrphan(Take $take, GenerationSnapshotDTO $snapshot): ReconcileResultDTO
    {
        $outcome = $this->applySnapshot->execute($take, $snapshot, flagUnpricedOrphan: true);
        $this->logUnpricedOrphan($take, $snapshot->id, $outcome);

        return new ReconcileResultDTO(ReconcileStatus::Terminal);
    }

    private function logUnpricedOrphan(Take $take, string $generationId, TransitionOutcome $outcome): void
    {
        Log::critical('Reconcile recorded an orphan success without its cost: the generation has no rates.', [
            'take_id' => $take->id,
            'generation_id' => $generationId,
            'outcome' => $outcome->value,
        ]);
    }

    private function rejected(Take $take, PixelforgeRejected $exception): ReconcileResultDTO
    {
        // Only an orphan fetch reaches here for a terminal take. The webhook
        // already recorded the orphan from its signed snapshot
        // (HandlePixelforgeWebhookAction), so nothing is failed again and no
        // reason is overwritten. A take with no reason yet gets a durable one,
        // so Needs attention still lists it (D-054).
        if ($take->status->isTerminal() && ($exception->status === 401 || $exception->status === 404)) {
            Log::critical('Reconcile could not fetch the late success of a failed take.', [
                'take_id' => $take->id,
                'http_status' => $exception->status,
                'error_code' => $exception->errorCode,
            ]);

            if ($take->refresh()->attention_reason === null) {
                $this->flagAttention->execute($take, AttentionReason::ReconcileExhausted);
            }

            return new ReconcileResultDTO(ReconcileStatus::Terminal);
        }

        if ($exception->status === 401) {
            // A revoked key never holds customer money (D-062): fail free now.
            Log::critical('Reconcile failed the take: Pixelforge rejected the API key.', ['take_id' => $take->id]);
            $this->failTake->execute($take, FailureCode::Internal, 'The API key was rejected while reconciling.');

            return new ReconcileResultDTO(ReconcileStatus::Revoked);
        }

        if ($exception->status === 404) {
            Log::warning('Reconcile stopped: Pixelforge has no such generation.', ['take_id' => $take->id]);
            $this->flagAttention->execute($take, AttentionReason::ReconcileExhausted);
            ReconcileStopMarker::mark($take->id, ReconcileStatus::NotFound);

            return new ReconcileResultDTO(ReconcileStatus::NotFound);
        }

        // Any other rejection of a GET (e.g. 429 daily_limit_exceeded) says
        // nothing about the generation: keep polling on the normal schedule.
        Log::warning('Reconcile poll rejected; polling again later.', [
            'take_id' => $take->id,
            'http_status' => $exception->status,
            'error_code' => $exception->errorCode,
        ]);

        return new ReconcileResultDTO(ReconcileStatus::Unavailable);
    }
}
