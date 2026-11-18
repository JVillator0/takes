<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Actions\Takes\ApplyTakeSnapshotAction;
use App\Actions\Takes\FlagTakeAttentionAction;
use App\DTOs\Webhooks\PixelforgeWebhookDTO;
use App\Enums\Takes\AttentionReason;
use App\Enums\Webhooks\PixelforgeEventType;
use App\Enums\Webhooks\WebhookOutcome;
use App\Exceptions\Takes\SnapshotRatesMissingException;
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
 * No HTTP here (M-16): a `succeeded` snapshot without `rates` is never
 * settled from cached prices (D-055). ApplyTakeSnapshotAction throws before
 * any write; the delivery answers 5xx (Pixelforge retries) and a one-off
 * reconcile fetches the generation and settles it (D-077(a)). For a failed
 * take that may hide that generation, the orphan is first recorded from this
 * signed snapshot without its cost, so a rejected fetch (401/404) cannot
 * lose it, and the retried delivery is acknowledged as `orphan_success`.
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

        try {
            return $this->record($event);
        } catch (SnapshotRatesMissingException $exception) {
            // D-077(a): a success that would settle has no rates. Nothing was
            // written (the event row rolled back with the rest), the request
            // answers 5xx and Pixelforge retries. Meanwhile the generation is
            // fetched outside any transaction and settles with its own rates
            // (D-055); the retried delivery then finds the take finished.
            Log::warning('Pixelforge succeeded webhook without rates; fetching the generation.', [
                'take_id' => $exception->takeId,
                'generation_id' => $exception->generationId,
            ]);
            $this->recordUnpricedOrphan($exception->takeId, $event);
            ReconcileTakeJob::fetchNow($exception->takeId, $exception->generationId);

            throw $exception;
        }
    }

    /**
     * The late success of a failed take that may hide this generation (an
     * orphan, M-14): record it now from the signed snapshot, in its own
     * transaction after the delivery's rolled back, without its cost (never
     * from cached prices, D-055). The fetch then prices it; if the fetch is
     * rejected (a revoked key, a 404) the orphan is not lost, and the retried
     * delivery finds the usage flagged and is acknowledged.
     */
    private function recordUnpricedOrphan(string $takeId, PixelforgeWebhookDTO $event): void
    {
        $take = Take::query()->find($takeId);

        if ($take === null || $event->snapshot === null || ! $take->failureMayHideAGeneration()) {
            return;
        }

        $this->applySnapshot->execute($take, $event->snapshot, flagUnpricedOrphan: true);
    }

    private function record(PixelforgeWebhookDTO $event): WebhookOutcome
    {
        return DB::transaction(function () use ($event): WebhookOutcome {
            $receivedAt = now();

            // ON CONFLICT DO NOTHING: a concurrent delivery of the same event
            // waits for this one to commit, then sees it as a duplicate.
            $isNew = DB::table('webhook_events')->insertOrIgnore([
                'event_id' => $event->eventId,
                'type' => $event->type,
                'generation_id' => $event->generationId,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'received_at' => $receivedAt,
            ]) === 1;

            $webhookEventId = DB::table('webhook_events')->where('event_id', $event->eventId)->value('id');

            $take = $event->eventType?->isGeneration() === true
                ? $this->findTake->execute($event->generationId, $event->appTakeId)
                : null;

            $outcome = $isNew
                ? $this->handle($event, $take)
                : WebhookOutcome::Duplicate;

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

        return WebhookOutcome::fromTransition($this->applySnapshot->execute($take, $snapshot));
    }
}
