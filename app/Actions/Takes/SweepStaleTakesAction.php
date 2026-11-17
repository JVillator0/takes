<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Actions\Suggestions\ExpireSuggestionAction;
use App\DTOs\Takes\SweepResultDTO;
use App\Enums\Suggestions\SuggestionStatus;
use App\Enums\Takes\TakeStatus;
use App\Jobs\ReconcileTakeJob;
use App\Models\Suggestion;
use App\Models\Take;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Takes\ReconcileStopMarker;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The opportunistic sweeper (D-036, W-16): no scheduler, so lost reconcile
 * chains and stuck suggestions are picked up whenever someone looks. Page
 * loads (SweepStaleTakes middleware) and `takes:reconcile --stale` call it.
 *
 * Throttled with a cache lock that is never released: it simply expires, so
 * the sweep runs at most once per THROTTLE_SECONDS whatever the traffic.
 * Idempotent: ReconcileTakeJob is unique per take, and expiring a
 * suggestion re-checks its status under lock.
 */
final readonly class SweepStaleTakesAction
{
    public const string LOCK = 'takes:sweep-stale';

    /** At most one sweep per this many seconds (≥ 2 min, D-036). */
    public const int THROTTLE_SECONDS = 120;

    /** A non-terminal take or pending suggestion older than this is stale. */
    public const int STALE_AFTER_SECONDS = 120;

    /** Bounds one sweep; the next one picks up the rest. */
    public const int BATCH = 100;

    public function __construct(
        private ExpireSuggestionAction $expireSuggestion,
        private PixelforgeClient $client,
    ) {}

    /**
     * @param  bool  $force  skip the throttle (the Artisan command)
     */
    public function execute(bool $force = false): SweepResultDTO
    {
        if (! $force && ! Cache::lock(self::LOCK, self::THROTTLE_SECONDS)->get()) {
            return SweepResultDTO::skipped();
        }

        $staleBefore = now()->subSeconds(self::STALE_AFTER_SECONDS);

        // Without an API key nothing can be polled; do not queue no-op polls.
        $takeIds = $this->client->isConfigured() ? $this->staleTakeIds($staleBefore) : [];

        foreach ($takeIds as $takeId) {
            ReconcileTakeJob::dispatch($takeId);
        }

        $expired = 0;

        // Pending past expires_at (CreateSuggestionAction sets it 2 min out),
        // the same rule ExpireSuggestionAction re-checks under lock.
        $suggestions = Suggestion::query()
            ->where('status', SuggestionStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->orderBy('created_at')
            ->limit(self::BATCH)
            ->get();

        foreach ($suggestions as $suggestion) {
            $expired += $this->expireSuggestion->execute($suggestion) ? 1 : 0;
        }

        return new SweepResultDTO(
            ran: true,
            reconcilesDispatched: count($takeIds),
            suggestionsExpired: $expired,
        );
    }

    /**
     * The oldest stale in-flight takes, up to BATCH, skipping only chains that
     * stopped for a definitive reason (404, a fetched generation without
     * rates; ReconcileStopMarker). A flag from a webhook or a run of 429/5xx
     * never stops the sweep (W-16). Stopped takes are filtered page by page
     * before the batch is cut, so they can never crowd out newer stale takes.
     *
     * @return list<string>
     */
    private function staleTakeIds(CarbonInterface $staleBefore): array
    {
        $takeIds = [];

        Take::query()
            ->whereIn('status', array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal()))
            ->where('created_at', '<=', $staleBefore)
            ->orderBy('created_at')
            ->orderBy('id')
            ->select('id')
            ->chunk(self::BATCH, function (Collection $page) use (&$takeIds): bool {
                $ids = $page->map(fn (Take $take): string => $take->id)->values()->all();
                $stopped = ReconcileStopMarker::stopped($ids);

                foreach ($ids as $id) {
                    if (! in_array($id, $stopped, true)) {
                        $takeIds[] = $id;
                    }
                }

                return count($takeIds) < self::BATCH;
            });

        return array_slice($takeIds, 0, self::BATCH);
    }
}
