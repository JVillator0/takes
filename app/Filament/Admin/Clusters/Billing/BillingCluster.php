<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Billing;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/**
 * Money: the Reports page, ledger entries, purchases and invoices (A07).
 * Hidden from the sidebar until it holds a page or resource the admin may open.
 */
final class BillingCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationLabel(): string
    {
        return __('console.clusters.billing');
    }

    public static function getClusterBreadcrumb(): string
    {
        return __('console.clusters.billing');
    }
}
