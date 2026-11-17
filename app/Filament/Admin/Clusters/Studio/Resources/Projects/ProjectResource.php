<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Projects;

use App\Filament\Admin\Clusters\Studio\Resources\Projects\Pages\ListProjects;
use App\Filament\Admin\Clusters\Studio\Resources\Projects\Pages\ViewProject;
use App\Filament\Admin\Clusters\Studio\Resources\Projects\RelationManagers\TakesRelationManager;
use App\Filament\Admin\Clusters\Studio\Resources\Projects\Schemas\ProjectInfolist;
use App\Filament\Admin\Clusters\Studio\Resources\Projects\Tables\ProjectsTable;
use App\Filament\Admin\Clusters\Studio\StudioCluster;
use App\Filament\Admin\Concerns\ReadsWithShieldPermissions;
use App\Models\Project;
use App\Queries\Projects\ListProjectsWithSpendQuery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Studio › Projects (A09): read-only, with take count and spend from one query; the view lists its takes.
 */
final class ProjectResource extends Resource
{
    use ReadsWithShieldPermissions;

    protected static ?string $model = Project::class;

    protected static ?string $cluster = StudioCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('console.projects.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('console.projects.plural');
    }

    /**
     * @return Builder<Project>
     */
    public static function getEloquentQuery(): Builder
    {
        return app(ListProjectsWithSpendQuery::class)->builder()->with('customer');
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProjectInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TakesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'view' => ViewProject::route('/{record}'),
        ];
    }
}
