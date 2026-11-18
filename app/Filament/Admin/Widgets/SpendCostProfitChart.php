<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\DTOs\Billing\DailySpendCostDTO;
use App\Enums\Billing\Provider;
use App\Filament\Admin\Widgets\Concerns\HasDashboardRange;
use App\Queries\Billing\ListDailySpendCostQuery;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Elemind\FilamentECharts\Widgets\EChartWidget;

/**
 * Dashboard (A02) "Spend, cost and profit": settled spend per UTC billing day stacked by provider, with provider
 * cost and profit as lines, over the last 14 or 30 days. Values are the exact nine-decimal strings of
 * Nanodollars (never a PHP float); ECharts reads them as numbers only to draw. Never polls (D-038).
 */
final class SpendCostProfitChart extends EChartWidget
{
    use HasDashboardRange;
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected static int $contentHeight = 320;

    protected function getHeading(): string
    {
        return __('console.dashboard.spend_chart.heading');
    }

    protected function getSubheading(): string
    {
        return __('console.dashboard.spend_chart.description');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        $days = app(ListDailySpendCostQuery::class)->execute($this->rangeFrom(), $this->rangeUntil());

        $series = array_map(fn (Provider $provider): array => [
            'name' => __('console.dashboard.spend_chart.spend', ['provider' => __('console.enums.provider.'.$provider->value)]),
            'type' => 'bar',
            'stack' => 'spend',
            'data' => array_map(fn (DailySpendCostDTO $day): string => $day->spendByProvider[$provider->value]->toDecimalString(), $days),
        ], Provider::cases());

        $series[] = [
            'name' => __('console.dashboard.spend_chart.cost'),
            'type' => 'line',
            'data' => array_map(fn (DailySpendCostDTO $day): string => $day->cost->toDecimalString(), $days),
        ];

        $series[] = [
            'name' => __('console.dashboard.spend_chart.profit'),
            'type' => 'line',
            'data' => array_map(fn (DailySpendCostDTO $day): string => $day->profit->toDecimalString(), $days),
        ];

        return [
            'backgroundColor' => 'transparent',
            'tooltip' => ['trigger' => 'axis'],
            'legend' => ['bottom' => 0],
            'grid' => ['left' => 8, 'right' => 16, 'top' => 16, 'bottom' => 40, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => array_map(fn (DailySpendCostDTO $day): string => $day->date, $days)],
            'yAxis' => ['type' => 'value', 'axisLabel' => ['formatter' => '${value}']],
            'series' => $series,
        ];
    }
}
