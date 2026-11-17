<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/**
 * Takes, projects and AI suggestions: what customers made (A07).
 * Hidden from the sidebar, and refused by URL, until it holds a page or resource the admin may open.
 */
final class StudioCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationLabel(): string
    {
        return __('console.clusters.studio');
    }

    public function getTitle(): string
    {
        return __('console.clusters.studio');
    }

    public static function canAccess(): bool
    {
        return self::canAccessClusteredComponents();
    }

    public static function getClusterBreadcrumb(): string
    {
        return __('console.clusters.studio');
    }
}
