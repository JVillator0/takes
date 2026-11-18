<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\Takes\TakeStatus;
use App\Filament\Admin\Widgets\Concerns\HasDashboardRange;
use App\Queries\Takes\ListTakeStatusCountsQuery;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Elemind\FilamentECharts\Widgets\EChartWidget;

/**
 * Dashboard (A02) "Takes by status": takes created in the last 14 or 30 UTC days, per status (spec 7
 * Definitions: takes created in range, all statuses). Never polls (D-038).
 */
final class TakesByStatusChart extends EChartWidget
{
    use HasDashboardRange;
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    protected function getHeading(): string
    {
        return __('console.dashboard.takes_by_status.heading');
    }

    protected function getSubheading(): string
    {
        return __('console.dashboard.takes_by_status.description');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        $counts = app(ListTakeStatusCountsQuery::class)->execute($this->rangeFrom());

        return [
            'backgroundColor' => 'transparent',
            'tooltip' => ['trigger' => 'axis'],
            'grid' => ['left' => 8, 'right' => 16, 'top' => 16, 'bottom' => 8, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => array_map(fn (TakeStatus $status): string => $status->getLabel(), TakeStatus::cases())],
            'yAxis' => ['type' => 'value', 'minInterval' => 1],
            'series' => [[
                'name' => __('console.dashboard.takes_by_status.heading'),
                'type' => 'bar',
                'data' => array_map(fn (TakeStatus $status): int => $counts[$status->value], TakeStatus::cases()),
            ]],
        ];
    }
}
