<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers\RelationManagers;

use App\Enums\Takes\TakeStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A05 "Takes" tab: the customer's takes, newest first (the full take view is Studio › Takes, A06). Part of the
 * customer's page, so it needs only the customer permission.
 */
final class TakesRelationManager extends RelationManager
{
    protected static string $relationship = 'takes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('console.customers.takes_tab.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof User && $admin->can('ViewAny:Customer');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label(__('console.customers.takes_tab.take'))->fontFamily('mono')
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 10, '…')),
                TextColumn::make('status')->label(__('console.customers.takes_tab.status'))->badge()
                    ->formatStateUsing(fn (TakeStatus $state): string => $state->label())
                    ->color(fn (TakeStatus $state): string => $state->color()),
                TextColumn::make('prompt')->label(__('console.customers.takes_tab.prompt'))->limit(60),
                TextColumn::make('created_at')->label(__('console.customers.takes_tab.created'))->dateTime('Y-m-d H:i:s'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
