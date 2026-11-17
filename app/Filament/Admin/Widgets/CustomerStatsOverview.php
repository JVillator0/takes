<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Queries\Customers\GetCustomerStatsQuery;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Header of Customers (A09): customers, active today, outstanding credits (liability), active holds and average spend per spending customer, exact
 * (D-042). Never polls (D-038).
 */
final class CustomerStatsOverview extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $stats = app(GetCustomerStatsQuery::class)->execute();

        return [
            Stat::make(__('console.customers.stats.customers'), (string) $stats->customers),
            Stat::make(__('console.customers.stats.active_today'), (string) $stats->activeToday),
            Stat::make(__('console.customers.stats.outstanding'), $stats->outstandingCredits->formatExact()),
            Stat::make(__('console.customers.stats.holds'), $stats->activeHolds->formatExact())
                ->description(__('console.customers.stats.holds_count', ['count' => $stats->openUsageRecords])),
            Stat::make(__('console.customers.stats.avg_spend'), $stats->averageSpend?->formatExact() ?? '-'),
        ];
    }
}
