<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Actions\Suggestions\ExpireSuggestionAction;
use App\DTOs\Takes\SweepResultDTO;
use App\Enums\Suggestions\SuggestionStatus;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\TakeStatus;
use App\Jobs\ReconcileTakeJob;
use App\Models\Suggestion;
use App\Models\Take;
use App\Services\Pixelforge\PixelforgeClient;
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

    /**
     * Chains that stopped for good: the full window ran out, or the
     * generation cannot be settled. An admin reconciles these by hand.
     */
    private const array STOPPED = [AttentionReason::ReconcileExhausted, AttentionReason::InvalidSnapshot];

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
        $takeIds = ! $this->client->isConfigured() ? collect() : Take::query()
            ->whereIn('status', array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal()))
            ->where('created_at', '<=', $staleBefore)
            ->where(fn ($query) => $query
                ->whereNull('attention_reason')
                ->orWhereNotIn('attention_reason', array_map(fn (AttentionReason $reason): string => $reason->value, self::STOPPED)))
            ->orderBy('created_at')
            ->limit(self::BATCH)
            ->pluck('id');

        foreach ($takeIds as $takeId) {
            ReconcileTakeJob::dispatch($takeId);
        }

        $expired = 0;

        $suggestions = Suggestion::query()
            ->where('status', SuggestionStatus::Pending->value)
            ->where('created_at', '<=', $staleBefore)
            ->orderBy('created_at')
            ->limit(self::BATCH)
            ->get();

        foreach ($suggestions as $suggestion) {
            $expired += $this->expireSuggestion->execute($suggestion) ? 1 : 0;
        }

        return new SweepResultDTO(
            ran: true,
            reconcilesDispatched: $takeIds->count(),
            suggestionsExpired: $expired,
        );
    }
}
