<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Takes\FlagTakeAttentionAction;
use App\Actions\Takes\ReconcileTakeAction;
use App\Enums\Takes\AttentionReason;
use App\Models\Take;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The polling safety net (spec 5, W-15): asks Pixelforge for the generation
 * and applies it, then polls again after 60, 120, 300, 600 and 840 s (about
 * 32 min, longer than Pixelforge's ~27 min of webhook retries) until the take
 * is terminal. When the chain runs out the take is flagged
 * `reconcile_exhausted` (D-054).
 *
 * Every delay is at most 840 s (Laravel Cloud's managed queue rejects more
 * than 900 s, M-17). A chain starts when the take first gets its
 * `pixelforge_id` (ApplyTakeSnapshotAction, delayed by the first step);
 * the sweeper restarts lost chains (D-036).
 *
 * Unique by take until it starts processing: while a poll is waiting in the
 * queue, another dispatch for the same take is dropped, and the running job
 * can still schedule its own next poll.
 */
final class ReconcileTakeJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Seconds before each poll; DELAYS[n] is the wait before poll n. */
    public const array DELAYS = [60, 120, 300, 600, 840];

    /** The longest wait the chain ever asks the queue for (≤ 900 s, M-17). */
    public const int MAX_DELAY_SECONDS = 840;

    /** A queued poll holds the take's lock at most this long. */
    public int $uniqueFor = 900;

    /** Each poll is its own job; a crash is recovered by the next sweep. */
    public int $tries = 1;

    /**
     * @param  int  $attempt  index into DELAYS of this poll
     * @param  bool  $continuesChain  false for a one-off fetch that never schedules another poll
     * @param  string|null  $generationId  for a take that has no `pixelforge_id` yet (found by webhook metadata)
     */
    public function __construct(
        public readonly string $takeId,
        public readonly int $attempt = 0,
        public readonly bool $continuesChain = true,
        public readonly ?string $generationId = null,
    ) {}

    /**
     * Starts a fresh chain for a take Pixelforge just accepted:
     * ApplyTakeSnapshotAction calls it when a take first gets its
     * `pixelforge_id` (create response, webhook or reconcile).
     */
    public static function start(string $takeId): void
    {
        self::dispatch($takeId)->delay(min(self::DELAYS[0], self::MAX_DELAY_SECONDS))->afterCommit();
    }

    /**
     * Polls once right away (after commit), e.g. when a succeeded webhook
     * came without the rates needed to settle it (D-077(a)). Unique on its
     * own key, so a chain poll already waiting in the queue does not swallow
     * it; it never schedules another poll, the chain carries on as before.
     * $generationId covers a take found by metadata that has no
     * `pixelforge_id` yet.
     */
    public static function fetchNow(string $takeId, ?string $generationId = null): void
    {
        self::dispatch($takeId, 0, false, $generationId)->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->continuesChain ? $this->takeId : "{$this->takeId}:now";
    }

    public function handle(ReconcileTakeAction $reconcile, FlagTakeAttentionAction $flagAttention): void
    {
        $take = Take::query()->find($this->takeId);

        if ($take === null) {
            return;
        }

        $result = $reconcile->execute($take, $this->generationId);

        if (! $this->continuesChain || ! $result->status->pollAgain()) {
            return;
        }

        $next = $this->attempt + 1;

        if (! array_key_exists($next, self::DELAYS)) {
            Log::warning('Reconcile exhausted: the take is still not finished.', [
                'take_id' => $take->id,
                'take_status' => $take->status->value,
            ]);
            $flagAttention->execute($take, AttentionReason::ReconcileExhausted);

            return;
        }

        // Honour Retry-After when Pixelforge asks for a longer wait (429/503).
        $delay = max(self::DELAYS[$next], $result->retryAfterSeconds ?? 0);

        self::dispatch($take->id, $next)->delay(min($delay, self::MAX_DELAY_SECONDS));
    }
}
