<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Takes;

use App\Filament\Admin\Clusters\Studio\Resources\Takes\Pages\ListTakes;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\Pages\ViewTake;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\RelationManagers\WebhookDeliveriesRelationManager;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\Schemas\TakeInfolist;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\Tables\TakesTable;
use App\Filament\Admin\Clusters\Studio\StudioCluster;
use App\Filament\Admin\Concerns\ReadsWithShieldPermissions;
use App\Models\Take;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Studio › Takes (A09 table, A06 view): read-only; the one action is Reconcile now. Columns read the take, its
 * customer, project and usage record, all eager-loaded.
 */
final class TakeResource extends Resource
{
    use ReadsWithShieldPermissions;

    protected static ?string $model = Take::class;

    protected static ?string $cluster = StudioCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('console.takes.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('console.takes.plural');
    }

    /**
     * @return Builder<Take>
     */
    public static function getEloquentQuery(): Builder
    {
        return Take::query()->with(['customer', 'project', 'usageRecord']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TakeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TakesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            WebhookDeliveriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTakes::route('/'),
            'view' => ViewTake::route('/{record}'),
        ];
    }
}
