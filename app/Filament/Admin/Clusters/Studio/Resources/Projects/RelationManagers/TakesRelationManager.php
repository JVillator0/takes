<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Projects\RelationManagers;

use App\Filament\Admin\Clusters\Studio\Resources\Takes\Tables\TakesTable;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\TakeResource;
use App\Models\Take;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The project's takes (A09 "View (takes relation manager)"), the same table as Studio › Takes; a row opens A06.
 */
final class TakesRelationManager extends RelationManager
{
    protected static string $relationship = 'takes';

    protected static ?string $relatedResource = TakeResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('console.takes.plural');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof User && $admin->can('ViewAny:Take');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return TakesTable::configure($table, scopedToProject: true)
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'project', 'usageRecord']))
            ->recordUrl(fn (Take $record): string => TakeResource::getUrl('view', ['record' => $record]));
    }
}
