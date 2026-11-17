<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Actions\Takes\ApplyTakeSnapshotAction;
use App\Actions\Takes\FlagTakeAttentionAction;
use App\DTOs\Webhooks\PixelforgeWebhookDTO;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\TakeStatus;
use App\Enums\Webhooks\PixelforgeEventType;
use App\Enums\Webhooks\WebhookOutcome;
use App\Jobs\ReconcileTakeJob;
use App\Models\Take;
use App\Models\WebhookDelivery;
use App\Queries\Takes\GetTakeForGenerationQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles one verified Pixelforge webhook delivery (spec 5).
 *
 * One transaction, nothing caught inside it (W-09): if anything throws, the
 * event row rolls back with everything else, the endpoint answers 5xx and
 * Pixelforge's retry delivers the event again as if it were new.
 *
 *  1. The event is recorded once by `event_id` (D-027, W-05).
 *  2. Every delivery appends one `webhook_deliveries` row with its outcome,
 *     duplicates included (W-06); a duplicate changes nothing else (W-07).
 *  3. `ping` and unknown types are acknowledged (W-10); a generation event
 *     goes to ApplyTakeSnapshotAction, the only writer of take status (W-12).
 *
 * No HTTP here (M-16): a `succeeded` snapshot without `rates` is not settled
 * from cached prices (D-055); the reconcile job fetches the generation after
 * commit and applies that snapshot instead (spec 3-04).
 */
final readonly class HandlePixelforgeWebhookAction
{
    public function __construct(
        private GetTakeForGenerationQuery $findTake,
        private ApplyTakeSnapshotAction $applySnapshot,
        private FlagTakeAttentionAction $flagAttention,
    ) {}

    public function execute(PixelforgeWebhookDTO $event): WebhookOutcome
    {
        Log::withContext([
            'webhook_event_id' => $event->eventId,
            'webhook_type' => $event->type,
            'webhook_attempt' => $event->attempt,
        ]);

        return DB::transaction(function () use ($event): WebhookOutcome {
            $receivedAt = now();

            $inserted = DB::table('webhook_events')->insertOrIgnoreReturning([
                'event_id' => $event->eventId,
                'type' => $event->type,
                'generation_id' => $event->generationId,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'received_at' => $receivedAt,
            ], ['id'], 'event_id')->first();

            $take = $event->eventType?->isGeneration() === true
                ? $this->findTake->execute($event->generationId, $event->appTakeId)
                : null;

            $outcome = $inserted === null
                ? WebhookOutcome::Duplicate
                : $this->handle($event, $take);

            $webhookEventId = $inserted?->id
                ?? DB::table('webhook_events')->where('event_id', $event->eventId)->value('id');

            (new WebhookDelivery)->forceFill([
                'event_id' => $event->eventId,
                'webhook_event_id' => $webhookEventId,
                'take_id' => $take?->id,
                'type' => $event->type,
                'attempt' => $event->attempt,
                'outcome' => $outcome,
                'received_at' => $receivedAt,
            ])->save();

            return $outcome;
        });
    }

    private function handle(PixelforgeWebhookDTO $event, ?Take $take): WebhookOutcome
    {
        if ($event->eventType === null) {
            Log::info('Pixelforge webhook ignored: unknown event type.');

            return WebhookOutcome::IgnoredType;
        }

        if ($event->eventType === PixelforgeEventType::Ping) {
            return WebhookOutcome::Ping;
        }

        if ($take === null) {
            Log::warning('Pixelforge webhook for an unknown take.', [
                'generation_id' => $event->generationId,
                'app_take_id' => $event->appTakeId,
            ]);

            return WebhookOutcome::UnknownTake;
        }

        $snapshot = $event->snapshot;

        if ($snapshot === null) {
            // The generation object breaks the provider's own invariants
            // (e.g. succeeded without usage): no state change, flagged (W-13).
            Log::critical('Pixelforge webhook carries an invalid generation.', [
                'take_id' => $take->id,
                'generation_id' => $event->generationId,
                'reason' => $event->snapshotError,
            ]);
            $this->flagAttention->execute($take, AttentionReason::InvalidSnapshot);

            return WebhookOutcome::InvalidSnapshot;
        }

        if ($snapshot->status === TakeStatus::Succeeded && $snapshot->rates === null) {
            // Settle only with the generation's own rates (D-055): fetch it
            // after commit, outside this transaction, and apply that snapshot.
            Log::warning('Pixelforge succeeded webhook without rates; fetching the generation.', [
                'take_id' => $take->id,
                'generation_id' => $snapshot->id,
            ]);
            ReconcileTakeJob::fetchNow($take->id);

            return WebhookOutcome::InvalidSnapshot;
        }

        return WebhookOutcome::fromTransition($this->applySnapshot->execute($take, $snapshot));
    }
}
