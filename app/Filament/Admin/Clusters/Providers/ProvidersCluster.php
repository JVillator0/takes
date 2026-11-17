<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Providers;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/**
 * The providers we pay: Pixelforge usage reconciliation, Anthropic usage, pricing and webhook events (A07).
 * Hidden from the sidebar, and refused by URL, until it holds a page or resource the admin may open.
 */
final class ProvidersCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationLabel(): string
    {
        return __('console.clusters.providers');
    }

    public function getTitle(): string
    {
        return __('console.clusters.providers');
    }

    public static function canAccess(): bool
    {
        return self::canAccessClusteredComponents();
    }

    public static function getClusterBreadcrumb(): string
    {
        return __('console.clusters.providers');
    }
}
