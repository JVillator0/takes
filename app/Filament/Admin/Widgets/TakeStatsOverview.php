<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Support\Money;
use App\Queries\Takes\GetTakeStatsQuery;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Header of Studio › Takes (A09): in progress, stuck > 15 min (D-053), failure rate and median duration over the
 * last 30 days. Never polls (D-038).
 */
final class TakeStatsOverview extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $stats = app(GetTakeStatsQuery::class)->execute();

        return [
            Stat::make(__('console.takes.in_progress'), (string) $stats->inProgress),
            Stat::make(__('console.takes.stuck'), (string) $stats->stuck)
                ->color($stats->stuck > 0 ? 'danger' : 'success'),
            Stat::make(__('console.takes.failure_rate'), Money::percentFromBps($stats->failureRateBps)),
            Stat::make(__('console.takes.median_duration'), $stats->medianDurationSeconds === null
                ? '-'
                : __('console.takes.duration_seconds', ['seconds' => $stats->medianDurationSeconds])),
        ];
    }
}
