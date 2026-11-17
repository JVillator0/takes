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

    public static function getNavigationLabel(): string
    {
        return __('console.dashboard.title');
    }
}
