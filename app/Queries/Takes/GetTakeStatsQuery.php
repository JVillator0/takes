<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\DTOs\Takes\TakeStatsDTO;
use App\Enums\Takes\TakeStatus;
use App\Models\Take;
use Carbon\CarbonImmutable;

/**
 * The numbers above Studio › Takes (A09), each one SQL aggregate over `takes` (spec 7 Definitions): failure rate
 * = failed ÷ terminal takes created in the range, median duration over succeeded takes in the range.
 */
final readonly class GetTakeStatsQuery
{
    public const int RANGE_DAYS = 30;

    public function __construct(private ListStuckTakesQuery $stuckTakes) {}

    public function execute(): TakeStatsDTO
    {
        $since = CarbonImmutable::now()->subDays(self::RANGE_DAYS);

        $inProgress = Take::query()->whereIn('status', array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal()))->count();

        $terminal = Take::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', [TakeStatus::Succeeded->value, TakeStatus::Failed->value])
            ->toBase()
            ->selectRaw('count(*) as terminal')
            ->selectRaw('count(*) filter (where status = ?) as failed', [TakeStatus::Failed->value])
            ->first();

        $terminalCount = (int) ($terminal->terminal ?? 0);
        $failedCount = (int) ($terminal->failed ?? 0);

        $median = Take::query()
            ->where('created_at', '>=', $since)
            ->where('status', TakeStatus::Succeeded->value)
            ->whereNotNull('submitted_at')
            ->whereNotNull('completed_at')
            ->toBase()
            ->selectRaw('floor(extract(epoch from percentile_cont(0.5) within group (order by completed_at - submitted_at)))::bigint as median_seconds')
            ->value('median_seconds');

        return TakeStatsDTO::fromArray([
            'in_progress' => $inProgress,
            'stuck' => $this->stuckTakes->builder()->count(),
            'failure_rate_bps' => $terminalCount === 0 ? null : intdiv($failedCount * 10_000, $terminalCount),
            'median_duration_seconds' => $median === null ? null : (int) $median,
        ]);
    }
}
