<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Billing\Widgets;

use App\DTOs\Billing\BillingReportFilterDTO;
use App\Filament\Admin\Clusters\Billing\Pages\Reports;
use App\Filament\Admin\Support\Money;
use App\Queries\Billing\GetBillingReportQuery;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The totals of Billing › Reports (#163) for the page's applied filter: spend, provider cost, profit, margin (20.0%
 * is the invariant; anything else is red) and promo/adjustment credits consumed (D-084). Lives only on that page.
 */
final class BillingReportStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return Reports::canAccess();
    }

    protected function getStats(): array
    {
        $filters = $this->pageFilters ?? [];
        $filter = BillingReportFilterDTO::fromArray([
            'from' => is_string($filters['from'] ?? null) ? $filters['from'] : '',
            'until' => is_string($filters['until'] ?? null) ? $filters['until'] : '',
            'group_by' => is_string($filters['group_by'] ?? null) ? $filters['group_by'] : BillingReportFilterDTO::BY_DAY,
            'customer_id' => is_int($filters['customer_id'] ?? null) ? $filters['customer_id'] : null,
            'provider' => is_string($filters['provider'] ?? null) ? $filters['provider'] : null,
        ]);
        $totals = app(GetBillingReportQuery::class)->execute($filter)->totals;
        $breaks = $totals->breaksMarginInvariant();

        return [
            Stat::make(__('console.reports.spend'), $totals->spend->formatExact()),
            Stat::make(__('console.reports.provider_cost'), $totals->cost->formatExact()),
            Stat::make(__('console.reports.profit'), $totals->profit()->formatExact()),
            Stat::make(__('console.reports.margin'), Money::percentFromBps($totals->marginBps()))
                ->description($breaks ? __('console.reports.margin_alert') : __('console.reports.margin_ok'))
                ->color($breaks ? 'danger' : 'success'),
            Stat::make(__('console.reports.promo_consumed'), $totals->promoConsumed->formatExact()),
        ];
    }
}
