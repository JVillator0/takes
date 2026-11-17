<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\DTOs\Takes\ReconcileResultDTO;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\ReconcileStatus;
use App\Exceptions\Pixelforge\PixelforgeBusy;
use App\Exceptions\Pixelforge\PixelforgeRejected;
use App\Exceptions\Pixelforge\PixelforgeUnavailable;
use App\Exceptions\Takes\SnapshotRatesMissingException;
use App\Models\Take;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * One reconcile poll (spec 5, W-15): read the generation from Pixelforge and
 * apply it like a webhook would. The safety net for lost or late webhooks;
 * used by ReconcileTakeJob, `takes:reconcile` and admin "Reconcile now".
 *
 * The GET happens with no transaction open and no row locked (M-16); the
 * snapshot is then applied by ApplyTakeSnapshotAction in its own transaction.
 */
final readonly class ReconcileTakeAction
{
    public function __construct(
        private PixelforgeClient $client,
        private ApplyTakeSnapshotAction $applySnapshot,
        private FailTakeAction $failTake,
        private FlagTakeAttentionAction $flagAttention,
    ) {}

    public function execute(Take $take): ReconcileResultDTO
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('ReconcileTakeAction calls Pixelforge and must run outside a transaction (M-16).');
        }

        $take->refresh();

        if ($take->status->isTerminal()) {
            return new ReconcileResultDTO(ReconcileStatus::Terminal);
        }

        if ($take->pixelforge_id === null) {
            // TODO(spec 4): re-dispatch SubmitTakeJob with the same idempotency key ("Lost jobs").
            Log::info('Reconcile skipped: the take has no generation yet.', ['take_id' => $take->id]);

            return new ReconcileResultDTO(ReconcileStatus::NotSubmitted);
        }

        if (! $this->client->isConfigured()) {
            return new ReconcileResultDTO(ReconcileStatus::NotConfigured);
        }

        try {
            $snapshot = $this->client->getGeneration($take->pixelforge_id);
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
            // Even the fetched generation has no rates: never settle from
            // cached prices (D-055). Nothing was written; flag it.
            Log::critical('Reconcile cannot settle: the generation has no rates.', ['take_id' => $take->id]);
            $this->flagAttention->execute($take, AttentionReason::InvalidSnapshot);

            return new ReconcileResultDTO(ReconcileStatus::Invalid);
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

    private function rejected(Take $take, PixelforgeRejected $exception): ReconcileResultDTO
    {
        if ($exception->status === 401) {
            // A revoked key never holds customer money (D-062): fail free now.
            Log::critical('Reconcile failed the take: Pixelforge rejected the API key.', ['take_id' => $take->id]);
            $this->failTake->execute($take, FailureCode::Internal, 'The API key was rejected while reconciling.');

            return new ReconcileResultDTO(ReconcileStatus::Revoked);
        }

        Log::warning('Reconcile stopped: Pixelforge has no such generation.', [
            'take_id' => $take->id,
            'status' => $exception->status,
            'error_code' => $exception->errorCode,
        ]);
        $this->flagAttention->execute($take, AttentionReason::ReconcileExhausted);

        return new ReconcileResultDTO(ReconcileStatus::NotFound);
    }
}
