<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio;

use App\Enums\Takes\TakeStatus;
use App\Models\Take;
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

    /**
     * Takes in progress right now (spec 7: Studio badge), one indexed count.
     */
    public static function getNavigationBadge(): ?string
    {
        $inProgress = Take::query()->whereIn('status', array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal()))->count();

        return $inProgress > 0 ? (string) $inProgress : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
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
