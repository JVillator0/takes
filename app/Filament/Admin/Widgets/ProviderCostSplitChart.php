<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\HasDashboardRange;
use App\Queries\Billing\ListProviderCostSplitQuery;
use App\Support\Money\Nanodollars;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Elemind\FilamentECharts\Widgets\EChartWidget;

/**
 * Dashboard (A02) provider cost split: settled provider cost per provider over the last 14 or 30 UTC days, as a
 * donut. Admin-only (D-023). Values are exact nine-decimal strings. Never polls (D-038).
 */
final class ProviderCostSplitChart extends EChartWidget
{
    use HasDashboardRange;
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected function getHeading(): string
    {
        return __('console.dashboard.cost_split.heading');
    }

    protected function getSubheading(): string
    {
        return __('console.dashboard.cost_split.description');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        $split = app(ListProviderCostSplitQuery::class)->execute($this->rangeFrom(), $this->rangeUntil());

        return [
            'backgroundColor' => 'transparent',
            'tooltip' => ['trigger' => 'item'],
            'legend' => ['bottom' => 0],
            'series' => [[
                'type' => 'pie',
                'radius' => ['45%', '70%'],
                'label' => ['show' => false],
                'data' => array_values(array_map(fn (string $provider, Nanodollars $cost): array => [
                    'name' => __('console.enums.provider.'.$provider),
                    'value' => $cost->toDecimalString(),
                ], array_keys($split), $split)),
            ]],
        ];
    }
}
