<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\MoneyStatsOverview;
use App\Filament\Admin\Widgets\NeedsAttentionTable;
use App\Filament\Admin\Widgets\ProviderCostSplitChart;
use App\Filament\Admin\Widgets\SpendCostProfitChart;
use App\Filament\Admin\Widgets\TakesByStatusChart;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * The console's home (A02, D-086): today's money stats, the spend/cost/profit chart, the provider cost split,
 * takes by status and Needs attention. Each widget carries its own Shield permission (`View:<Widget>`) and never
 * polls (D-038): the page shows when it was read and refreshes on an explicit click.
 */
final class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return __('console.dashboard.title');
    }

    public function getSubheading(): string
    {
        return __('console.dashboard.updated', ['time' => CarbonImmutable::now('UTC')->format('H:i')]);
    }

    /**
     * The dashboard lists its widgets itself: the panel also discovers the resource header widgets (so Shield gives
     * them a permission), and those belong on their own pages.
     *
     * @return list<class-string>
     */
    public function getWidgets(): array
    {
        return [
            MoneyStatsOverview::class,
            SpendCostProfitChart::class,
            ProviderCostSplitChart::class,
            TakesByStatusChart::class,
            NeedsAttentionTable::class,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getColumns(): array
    {
        return ['md' => 2];
    }

    public static function getNavigationLabel(): string
    {
        return __('console.dashboard.title');
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('console.dashboard.refresh'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->redirect(self::getUrl(), navigate: true)),
        ];
    }
}
