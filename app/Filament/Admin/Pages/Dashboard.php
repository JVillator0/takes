<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The console's home (A02). Its widgets (money stats, spend/cost/profit chart, provider cost split, Needs
 * attention) arrive with spec 7 issue 6; each one carries its own Shield permission and never polls (D-038).
 */
final class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return __('console.dashboard.title');
    }

    /**
     * The dashboard lists its widgets itself: the panel also discovers the resource header widgets (so Shield gives
     * them a permission), and those belong on their own pages.
     *
     * @return list<class-string>
     */
    public function getWidgets(): array
    {
        return [];
    }

    public static function getNavigationLabel(): string
    {
        return __('console.dashboard.title');
    }
}
