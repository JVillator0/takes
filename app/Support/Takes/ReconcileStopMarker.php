<?php

declare(strict_types=1);

namespace App\Support\Takes;

use App\Enums\Takes\ReconcileStatus;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers that a take's reconcile chain stopped for a definitive reason
 * (spec 5, W-16): Pixelforge answered 404 for the generation, or the fetched
 * generation has no `rates`, so polling again cannot help. The sweeper skips
 * those takes; every other stop (a run of 429/5xx answers, a lost job, a
 * malformed webhook) keeps being swept.
 *
 * Kept in the default cache store (the database on Laravel Cloud) for 30
 * days; a reconcile that later applies a snapshot clears it.
 */
final class ReconcileStopMarker
{
    private const string PREFIX = 'takes:reconcile-stopped:';

    private const int TTL_DAYS = 30;

    public static function mark(string $takeId, ReconcileStatus $status): void
    {
        Cache::put(self::PREFIX.$takeId, $status->value, now()->addDays(self::TTL_DAYS));
    }

    public static function forget(string $takeId): void
    {
        Cache::forget(self::PREFIX.$takeId);
    }

    /**
     * The ids among $takeIds whose chain stopped for good.
     *
     * @param  array<int, string>  $takeIds
     * @return list<string>
     */
    public static function stopped(array $takeIds): array
    {
        if ($takeIds === []) {
            return [];
        }

        $values = Cache::many(array_map(fn (string $id): string => self::PREFIX.$id, $takeIds));

        return array_values(array_filter(
            $takeIds,
            fn (string $id): bool => ($values[self::PREFIX.$id] ?? null) !== null,
        ));
    }
}
