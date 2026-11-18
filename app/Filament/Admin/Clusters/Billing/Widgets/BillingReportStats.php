<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Billing\Widgets;

use App\DTOs\Billing\BillingReportRowDTO;
use App\Filament\Admin\Clusters\Billing\Pages\Reports;
use App\Filament\Admin\Support\Money;
use App\Support\Money\Nanodollars;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Reactive;

/**
 * The totals of Billing › Reports (#163): spend, provider cost, profit, margin (20.0% is the invariant; anything else
 * is red) and promo/adjustment credits consumed (D-084). It runs no query: the page passes the totals of the report
 * it already computed (a reactive prop the client cannot write), so the report runs once per request. No polling
 * (FI-10, D-038). Not discovered; gated on the Reports page permission (filament.md FI-07 exception).
 */
final class BillingReportStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    /** @var array{spend_nanos: int, cost_nanos: int, promo_consumed_nanos: int} */
    #[Reactive]
    public array $totals = ['spend_nanos' => 0, 'cost_nanos' => 0, 'promo_consumed_nanos' => 0];

    public static function canView(): bool
    {
        return Reports::canAccess();
    }

    /**
     * The widget props for a report's totals row.
     *
     * @return array{totals: array{spend_nanos: int, cost_nanos: int, promo_consumed_nanos: int}}
     */
    public static function props(BillingReportRowDTO $totals): array
    {
        return ['totals' => [
            'spend_nanos' => $totals->spend->toInt(),
            'cost_nanos' => $totals->cost->toInt(),
            'promo_consumed_nanos' => $totals->promoConsumed->toInt(),
        ]];
    }

    protected function getStats(): array
    {
        $totals = BillingReportRowDTO::fromArray([
            'key' => 'total',
            'label' => 'Total',
            'spend_nanos' => $this->totals['spend_nanos'],
            'cost_nanos' => $this->totals['cost_nanos'],
            'pixelforge_cost_nanos' => 0,
            'anthropic_cost_nanos' => 0,
            'generations_charged' => 0,
            'suggestions' => 0,
            'takes' => 0,
            'failed_takes' => 0,
            'promo_consumed_nanos' => $this->totals['promo_consumed_nanos'],
        ]);
        $breaks = $totals->breaksMarginInvariant();

        return [
            Stat::make(__('console.reports.spend'), $totals->spend->formatExact()),
            Stat::make(__('console.reports.provider_cost'), $totals->cost->formatExact()),
            Stat::make(__('console.reports.profit'), $totals->profit()->formatExact()),
            Stat::make(__('console.reports.margin'), Money::percentFromBps($totals->marginBps()))
                ->description($breaks ? __('console.reports.margin_alert') : __('console.reports.margin_ok'))
                ->color($breaks ? 'danger' : 'success'),
            Stat::make(__('console.reports.promo_consumed'), Nanodollars::fromInt($this->totals['promo_consumed_nanos'])->formatExact()),
        ];
    }
}
