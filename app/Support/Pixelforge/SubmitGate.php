<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

use App\Enums\Takes\TakeStatus;
use App\Models\Take;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Global in-flight cap for Pixelforge creates (spec 3-04 issue 3).
 *
 * Pixelforge allows 3 queued or processing generations per account. Before
 * calling createGeneration the submit job asks acquire(): under a short
 * lock on the database cache store (shared by every worker) it counts
 *
 *   takes with a pixelforge_id that are not terminal
 *   + takes currently submitting (a reservation, below)
 *
 * and, below the cap, reserves a slot for this take. At the cap it answers
 * "release in 3-8 s" and the job never calls the API, so a herd of jobs
 * does not burn the 60 requests/min budget on rejected calls.
 * PixelforgeBusy stays the fallback when another app shares the account.
 *
 * A reservation lasts until finish() or until it expires
 * (`gate.reservation_seconds`, longer than the HTTP timeouts), so a crashed
 * job cannot hold a slot forever. finish() is called only once the create
 * response is persisted (the take has its pixelforge_id) or the answer was
 * a definite non-creation (busy, rejected); after an ambiguous outcome the
 * reservation is left to expire, because the generation may exist (#98).
 */
final class SubmitGate
{
    public const string LOCK_KEY = 'pixelforge:submit-gate';

    public const string RESERVATIONS_KEY = 'pixelforge:submit-gate:submitting';

    /**
     * @param  string  $holder  the caller's own token (one per submit job): only it can free the slot
     */
    public function acquire(Take $take, string $holder): SubmitGateDecision
    {
        $lock = $this->lock();

        try {
            $lock->block(config()->integer('pixelforge.gate.lock_wait_seconds'));
        } catch (LockTimeoutException) {
            return SubmitGateDecision::releaseAfter($this->jitter());
        }

        try {
            return $this->decide($take, $holder);
        } finally {
            $lock->release();
        }
    }

    /**
     * Frees this take's "submitting" slot once the create call has returned,
     * only if `$holder` reserved it: another job's finish() never frees it.
     */
    public function finish(Take $take, string $holder): void
    {
        $lock = $this->lock();

        try {
            $lock->block(config()->integer('pixelforge.gate.lock_wait_seconds'));
        } catch (LockTimeoutException) {
            return; // The reservation expires on its own.
        }

        try {
            $reservations = $this->reservations();

            if (($reservations[$take->id]['holder'] ?? null) === $holder) {
                unset($reservations[$take->id]);
                $this->saveReservations($reservations);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * True while this take holds a "submitting" slot: a create call may be
     * in flight, so a cancel must wait (spec 4 "Cancel", D-044). Read
     * without the gate lock; a stale answer only ever refuses a cancel.
     */
    public function isSubmitting(Take $take): bool
    {
        return array_key_exists($take->id, $this->reservations());
    }

    /** Generations counted against the cap right now (for logs and tests). */
    public function inFlight(): int
    {
        return $this->providerInFlight() + $this->submittingCount($this->reservations(), exceptTakeId: null);
    }

    private function decide(Take $take, string $holder): SubmitGateDecision
    {
        $reservations = $this->reservations();
        $current = $reservations[$take->id]['holder'] ?? null;

        // Another job is submitting this take right now: never take over its
        // slot (two creates with one key would race), wait instead.
        if ($current !== null && $current !== $holder) {
            return SubmitGateDecision::releaseAfter($this->jitter());
        }

        $inFlight = $this->providerInFlight() + $this->submittingCount($reservations, exceptTakeId: $take->id);

        if ($inFlight >= config()->integer('pixelforge.gate.concurrency_cap')) {
            return SubmitGateDecision::releaseAfter($this->jitter());
        }

        $reservations[$take->id] = [
            'holder' => $holder,
            'expires_at' => CarbonImmutable::now()
                ->addSeconds(config()->integer('pixelforge.gate.reservation_seconds'))
                ->getTimestamp(),
        ];
        $this->saveReservations($reservations);

        return SubmitGateDecision::allow();
    }

    /** Accepted by Pixelforge and not finished yet. */
    private function providerInFlight(): int
    {
        return Take::query()
            ->whereNotNull('pixelforge_id')
            ->whereIn('status', array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal()))
            ->count();
    }

    /**
     * Reserved takes still waiting for their create response. A take that
     * already has its pixelforge_id is counted by providerInFlight() instead.
     *
     * @param  array<string, array{holder: string, expires_at: int}>  $reservations
     */
    private function submittingCount(array $reservations, ?string $exceptTakeId): int
    {
        unset($reservations[(string) $exceptTakeId]);

        if ($reservations === []) {
            return 0;
        }

        return Take::query()
            ->whereKey(array_keys($reservations))
            ->whereNull('pixelforge_id')
            ->where('status', TakeStatus::Pending->value)
            ->count();
    }

    /**
     * Live reservations: take id ⇒ holder token and expiry (unix seconds).
     * Expired or unreadable ones are dropped.
     *
     * @return array<string, array{holder: string, expires_at: int}>
     */
    private function reservations(): array
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $stored = $this->store()->get(self::RESERVATIONS_KEY);
        $live = [];

        foreach (is_array($stored) ? $stored : [] as $takeId => $reservation) {
            $holder = is_array($reservation) ? ($reservation['holder'] ?? null) : null;
            $expiresAt = is_array($reservation) ? ($reservation['expires_at'] ?? null) : null;

            if (is_string($takeId) && is_string($holder) && is_int($expiresAt) && $expiresAt > $now) {
                $live[$takeId] = ['holder' => $holder, 'expires_at' => $expiresAt];
            }
        }

        return $live;
    }

    /**
     * @param  array<string, array{holder: string, expires_at: int}>  $reservations
     */
    private function saveReservations(array $reservations): void
    {
        if ($reservations === []) {
            $this->store()->forget(self::RESERVATIONS_KEY);

            return;
        }

        $this->store()->put(self::RESERVATIONS_KEY, $reservations, config()->integer('pixelforge.gate.reservation_seconds'));
    }

    private function jitter(): int
    {
        return random_int(
            config()->integer('pixelforge.gate.jitter_min_seconds'),
            config()->integer('pixelforge.gate.jitter_max_seconds'),
        );
    }

    private function store(): Repository
    {
        return Cache::store(config()->string('pixelforge.gate.store'));
    }

    private function lock(): Lock
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The submit gate needs a cache store that supports locks.');
        }

        return $store->lock(self::LOCK_KEY, config()->integer('pixelforge.gate.lock_seconds'));
    }
}
