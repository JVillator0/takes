<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\Takes\TakeStatus;
use App\Filament\Admin\Support\Money;
use App\Queries\Billing\GetMoneyTotalsQuery;
use App\Queries\Customers\GetCustomerStatsQuery;
use App\Queries\Takes\ListTakeStatusCountsQuery;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard (A02): today's UTC billing day in exact money (D-042): spend, provider cost, profit with its margin
 * (spec 7 Definitions, `-` when nothing was spent), the takes created today that succeeded or failed, and the
 * active holds (Σ held, with the open usage records). Admin-only figures (D-023). Never polls (D-038).
 */
final class MoneyStatsOverview extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /** @var array<string, int> */
    protected int|array|null $columns = ['md' => 3, 'xl' => 5];

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $money = app(GetMoneyTotalsQuery::class)->execute($today, $today);
        $takes = app(ListTakeStatusCountsQuery::class)->execute($today);
        $holds = app(GetCustomerStatsQuery::class)->execute();
        $billingDay = __('console.dashboard.stats.billing_day', ['date' => $today->toDateString()]);

        $running = array_sum(array_map(fn (TakeStatus $status): int => $takes[$status->value], TakeStatus::nonTerminal()));

        return [
            Stat::make(__('console.dashboard.stats.spend_today'), $money->spend->formatExact())
                ->description($billingDay),
            Stat::make(__('console.dashboard.stats.cost_today'), $money->cost->formatExact())
                ->description($billingDay),
            Stat::make(__('console.dashboard.stats.profit_today'), $money->profit->formatExact())
                ->description(__('console.dashboard.stats.margin', ['margin' => Money::percentFromBps($money->marginBps)]))
                ->color($money->profit->isNegative() ? 'danger' : 'success'),
            Stat::make(__('console.dashboard.stats.takes_today'), __('console.dashboard.stats.takes_today_value', [
                'succeeded' => $takes[TakeStatus::Succeeded->value],
                'failed' => $takes[TakeStatus::Failed->value],
            ]))
                ->description(__('console.dashboard.stats.takes_today_description', [
                    'created' => array_sum($takes),
                    'running' => $running,
                ])),
            Stat::make(__('console.dashboard.stats.holds'), $holds->activeHolds->formatExact())
                ->description(__('console.dashboard.stats.holds_count', ['count' => $holds->openUsageRecords])),
        ];
    }
}
