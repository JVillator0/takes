<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio;

use App\Enums\Takes\TakeStatus;
use App\Filament\Admin\Clusters\Studio\Resources\Projects\ProjectResource;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\TakeResource;
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

    /**
     * Takes first, then Projects (the cluster opens on its first item). FI-03 keeps navigation order in code
     * rather than `$navigationSort`, so the order lives here.
     *
     * @return array<class-string>
     */
    public static function getClusteredComponents(): array
    {
        $order = [TakeResource::class, ProjectResource::class];
        $components = parent::getClusteredComponents();
        usort($components, fn (string $a, string $b): int => (array_search($a, $order, true) === false ? PHP_INT_MAX : array_search($a, $order, true))
            <=> (array_search($b, $order, true) === false ? PHP_INT_MAX : array_search($b, $order, true)));

        return $components;
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
