<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Queries\Customers\GetCustomerStatsQuery;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Header of Customers (A09): customers, active today, outstanding credits (liability) and active holds, exact
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
            Stat::make(__('console.customers.stats.holds'), $stats->activeHolds->formatExact()),
        ];
    }
}
