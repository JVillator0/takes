<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Takes\ApplyTakeSnapshotAction;
use App\Actions\Takes\FailTakeAction;
use App\Actions\Takes\RecordSubmitAttemptAction;
use App\Actions\Takes\RecordSubmitOutcomeAction;
use App\DTOs\Pixelforge\CreateGenerationDTO;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeStatus;
use App\Exceptions\Pixelforge\PixelforgeBusy;
use App\Exceptions\Pixelforge\PixelforgeNotConfigured;
use App\Exceptions\Pixelforge\PixelforgeRejected;
use App\Exceptions\Pixelforge\PixelforgeUnavailable;
use App\Models\Take;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Pixelforge\SubmitGate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends a pending take to Pixelforge (spec 4 "Submit", the 3-04 error table).
 *
 *   202 (or a replay)      ⇒ ApplyTakeSnapshotAction (safe if a webhook already moved it)
 *   PixelforgeBusy         ⇒ `capacity`, release after max(Retry-After, a
 *                            backoff growing 20, 40 … 120 s) + jitter (#149)
 *   PixelforgeUnavailable  ⇒ `ambiguous` (sticky), release after 5 s + jitter, same key
 *   PixelforgeRejected     ⇒ FailTakeAction now, free (content_policy, validation,
 *                            daily_limit, or internal + critical log for 401/404/409)
 *   30 min after created_at ⇒ `submit_timeout` if any submit was in doubt, else
 *                            `provider_busy` (D-025, D-049), free
 *
 * Every attempt sends the take's idempotency key, so a replay after a
 * timeout is never a second generation (D-033). No transaction is open and
 * no row is locked during the HTTP call (M-16). Every release is capped at
 * 900 s (M-17) and never lands later than one second past the 30-minute
 * window; `retryUntil` outlives that window by one maximum release, so the
 * window (handle() expiring the take), not the worker's attempt limit, ends
 * the take (#149).
 *
 * Before calling, the job asks the app-wide request budget (50/min, below
 * Pixelforge's 60) and the 3-04 in-flight gate (3); when either says no it
 * releases shortly without calling and without counting an attempt, so
 * those waits never grow the capacity backoff.
 *
 * Unique per take for the whole submit window plus one maximum release:
 * the reconcile job and the sweeper re-dispatch it for a pending take with
 * no generation (spec 4 "Lost jobs"), and that dispatch is dropped for as
 * long as this job can still run. Only a job that was really lost (its lock
 * expired) is replaced. Its gate slot is reserved under this job's own
 * token, so even then one job's finish() never frees another's slot.
 */
final class SubmitTakeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Laravel Cloud's managed queue rejects longer delays (M-17). */
    public const int MAX_DELAY_SECONDS = 900;

    /** Retry delay after an ambiguous outcome or an exhausted request budget. */
    public const int RETRY_SECONDS = 5;

    /**
     * Minimum wait after a capacity answer grows by this much per API attempt
     * (20, 40, 60 … s), so one take waiting for capacity makes at most two
     * calls in any minute against the shared 60 rpm budget (#149).
     */
    public const int CAPACITY_BACKOFF_STEP_SECONDS = 20;

    /** The capacity backoff stops growing here (always well below 900 s, M-17). */
    public const int CAPACITY_BACKOFF_MAX_SECONDS = 120;

    /** Random seconds added to every release, so released jobs do not stampede. */
    public const int JITTER_SECONDS = 5;

    /** App-wide create requests per minute, below Pixelforge's 60 per account. */
    public const int REQUESTS_PER_MINUTE = 50;

    public const string RATE_LIMIT_KEY = 'pixelforge:submit';

    /** Released attempts are not capped; retryUntil() bounds the job (D-025). */
    public int $tries = 0;

    /** This job's own claim on the gate slot (kept across its releases). */
    public readonly string $holder;

    public function __construct(public readonly string $takeId)
    {
        $this->holder = (string) Str::uuid();
    }

    /**
     * Seconds the unique lock lives: the 30-minute window plus one maximum
     * release plus a minute of grace, so the lock never expires while this
     * job can still be released into the queue (its TTL is not renewed on
     * release).
     */
    public function uniqueFor(): int
    {
        return config()->integer('pixelforge.submit.window_minutes') * 60 + self::MAX_DELAY_SECONDS + 60;
    }

    public function uniqueId(): string
    {
        return $this->takeId;
    }

    /**
     * The 30-minute submit window (anchored on takes.created_at only, spec 4
     * "Thresholds") plus one maximum release and a minute of grace: the last
     * release lands just past the window, and handle() then fails the take
     * by its last outcome. The worker gives up only if that run never came.
     */
    public function retryUntil(): DateTimeInterface
    {
        $take = Take::query()->find($this->takeId);

        return $take === null
            ? CarbonImmutable::now()
            : self::windowEnd($take)->addSeconds(self::MAX_DELAY_SECONDS + 60);
    }

    /**
     * Unexpected exceptions (a database error, say) retry the same way.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 60];
    }

    public function handle(
        PixelforgeClient $client,
        SubmitGate $gate,
        RecordSubmitAttemptAction $recordAttempt,
        RecordSubmitOutcomeAction $recordOutcome,
        ApplyTakeSnapshotAction $applySnapshot,
        FailTakeAction $failTake,
    ): void {
        $take = Take::query()->find($this->takeId);

        // Idempotent: cancelled, failed, or already accepted ⇒ nothing to do.
        if ($take === null || $take->status !== TakeStatus::Pending || $take->pixelforge_id !== null) {
            return;
        }

        if (CarbonImmutable::now()->greaterThanOrEqualTo(self::windowEnd($take))) {
            $this->expire($take, $failTake);

            return;
        }

        if (! $client->isConfigured()) {
            // A configuration problem never holds customer money (D-062).
            Log::critical('Submit failed the take: Pixelforge is not configured.', ['take_id' => $take->id]);
            $failTake->execute($take, FailureCode::Internal, 'Pixelforge is not configured.');

            return;
        }

        if (RateLimiter::tooManyAttempts(self::RATE_LIMIT_KEY, self::REQUESTS_PER_MINUTE)) {
            $this->releaseWithin($take, self::RETRY_SECONDS + $this->jitter());

            return;
        }

        $decision = $gate->acquire($take, $this->holder);

        if (! $decision->allowed) {
            // Three already in flight: nothing is sent, so nothing can exist (D-044).
            $recordOutcome->execute($take, $this->waitingKind($take), 'concurrency_limit_exceeded');
            $this->releaseWithin($take, $decision->releaseAfterSeconds);

            return;
        }

        $waitingKind = $this->waitingKind($take);

        if (! $recordAttempt->execute($take)) {
            // Cancelled while we waited for the gate.
            $gate->finish($take, $this->holder);

            return;
        }

        RateLimiter::hit(self::RATE_LIMIT_KEY, 60);

        try {
            $snapshot = $client->createGeneration(CreateGenerationDTO::forTake($take), $take->idempotency_key);
        } catch (PixelforgeBusy $exception) {
            // Definite non-creation: free the slot now (#98).
            $gate->finish($take, $this->holder);
            $recordOutcome->execute($take, $waitingKind, $exception->errorCode);
            $this->releaseWithin($take, $this->capacityDelay($take, $exception->retryAfter));

            return;
        } catch (PixelforgeUnavailable $exception) {
            // The generation may exist: keep the slot until it expires (#98)
            // and retry the same key; a replay is free.
            $recordOutcome->execute($take, SubmitErrorKind::Ambiguous, $exception->errorCode ?? 'unavailable');
            $this->releaseWithin($take, self::RETRY_SECONDS + $this->jitter());

            return;
        } catch (PixelforgeRejected $exception) {
            $gate->finish($take, $this->holder);
            $failTake->execute($take, $exception->failureCode(), $exception->getMessage());

            return;
        } catch (PixelforgeNotConfigured $exception) {
            $gate->finish($take, $this->holder);
            Log::critical('Submit failed the take: Pixelforge is not configured.', ['take_id' => $take->id]);
            $failTake->execute($take, FailureCode::Internal, $exception->getMessage());

            return;
        }

        // Starts the reconcile chain when the take first gets its pixelforge_id (spec 5).
        $applySnapshot->execute($take, $snapshot);
        $gate->finish($take, $this->holder);
    }

    /**
     * The worker fails the job once retryUntil() has passed: the take's
     * window is over, so it fails free (D-025). Any other failure leaves
     * the take for the reconcile job and the sweeper to re-dispatch.
     */
    public function failed(?Throwable $exception): void
    {
        $take = Take::query()->find($this->takeId);

        if ($take === null || $take->status !== TakeStatus::Pending || $take->pixelforge_id !== null) {
            return;
        }

        if (CarbonImmutable::now()->greaterThanOrEqualTo(self::windowEnd($take))) {
            $this->expire($take, app(FailTakeAction::class));

            return;
        }

        Log::error('Submit job failed before the take window ended.', [
            'take_id' => $take->id,
            'reason' => $exception?->getMessage(),
        ]);
    }

    public static function windowEnd(Take $take): CarbonImmutable
    {
        return ($take->created_at ?? CarbonImmutable::now())
            ->addMinutes(config()->integer('pixelforge.submit.window_minutes'));
    }

    /**
     * After 30 minutes the take fails free, by its last outcome (spec 4):
     * a submit in doubt may have created a generation (`submit_timeout`, a
     * later success is `orphan_success`); only capacity answers mean nothing
     * was ever created (`provider_busy`).
     */
    private function expire(Take $take, FailTakeAction $failTake): void
    {
        $code = $this->inDoubt($take) ? FailureCode::SubmitTimeout : FailureCode::ProviderBusy;

        Log::warning('Submit window expired: the take fails free.', [
            'take_id' => $take->id,
            'failure_code' => $code->value,
            'submit_attempts' => $take->submit_attempts,
        ]);

        $failTake->execute($take, $code, 'The 30-minute submit window ended.');
    }

    /**
     * A submit is in doubt when an attempt may have reached Pixelforge
     * without a definite answer: an ambiguous outcome, or an attempt that
     * was counted but never recorded (the worker died during the call).
     */
    private function inDoubt(Take $take): bool
    {
        return $take->last_submit_error_kind === SubmitErrorKind::Ambiguous
            || ($take->last_submit_error_kind === null && $take->submit_attempts > 0);
    }

    /** The kind a capacity wait records: never `capacity` while a submit is in doubt. */
    private function waitingKind(Take $take): SubmitErrorKind
    {
        return $this->inDoubt($take) ? SubmitErrorKind::Ambiguous : SubmitErrorKind::Capacity;
    }

    /**
     * Releases for `$seconds`, but never past the end of the window (the
     * last release lands one second after it, so the expiry runs on time)
     * and never above 900 s.
     */
    private function releaseWithin(Take $take, int $seconds): void
    {
        $untilWindowEnd = max(1, self::windowEnd($take)->getTimestamp() - CarbonImmutable::now()->getTimestamp() + 1);

        $this->release(min($seconds, $untilWindowEnd, self::MAX_DELAY_SECONDS));
    }

    /**
     * Seconds to wait after a capacity answer: Retry-After, but never less
     * than a backoff that grows with the take's API attempts (gate and budget
     * waits are not attempts), plus jitter, capped at 900 s (M-17).
     */
    private function capacityDelay(Take $take, int $retryAfter): int
    {
        $backoff = min(
            self::CAPACITY_BACKOFF_STEP_SECONDS * max(1, $take->submit_attempts),
            self::CAPACITY_BACKOFF_MAX_SECONDS,
        );

        return min(max($retryAfter, $backoff) + $this->jitter(), self::MAX_DELAY_SECONDS);
    }

    private function jitter(): int
    {
        return random_int(0, self::JITTER_SECONDS);
    }
}
