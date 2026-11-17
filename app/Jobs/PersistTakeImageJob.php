<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Takes\PersistTakeImageAction;
use App\Models\Take;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a copy of a succeeded take's image (spec 4 "Persist image", D-020),
 * dispatched by ApplyTakeSnapshotAction after the settle commits. Retries a
 * failed download after 30 s, 5 min, then every 15 min (never above 900 s,
 * M-17) while the provider URL is still valid: until completed_at + 23 h.
 * Failure is non-fatal: the UI keeps the provider URL until it expires.
 */
final class PersistTakeImageJob implements ShouldQueue
{
    use Queueable;

    /** Hours the provider URL is still worth retrying (it expires after 24 h). */
    public const int RETRY_HOURS = 23;

    /** Bounded by retryUntil(), not by a count. */
    public int $tries = 0;

    public function __construct(public readonly string $takeId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        $completedAt = Take::query()->find($this->takeId)?->completed_at;

        return ($completedAt ?? CarbonImmutable::now())->addHours(self::RETRY_HOURS);
    }

    public function handle(PersistTakeImageAction $persistImage): void
    {
        $take = Take::query()->find($this->takeId);

        if ($take !== null) {
            $persistImage->execute($take);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Take image was not persisted; the provider URL stays in use until it expires.', [
            'take_id' => $this->takeId,
            'reason' => $exception?->getMessage(),
        ]);
    }
}
