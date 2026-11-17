<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Projects\Tables;

use App\Enums\Takes\TakeStatus;
use App\Filament\Admin\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Studio › Projects (A09): project, customer, takes, spend (exact), updated; newest activity first.
 */
final class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('console.projects.name'))->searchable(),
                TextColumn::make('customer.name')->label(__('console.common.customer')),
                TextColumn::make('takes_count')->label(__('console.projects.takes'))->alignEnd()->sortable(),
                TextColumn::make('spend_nanos')->label(__('console.projects.spend'))
                    ->formatStateUsing(fn (int|string $state): string => Money::exact($state))
                    ->alignEnd(),
                TextColumn::make('updated_at')->label(__('console.common.updated'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('customer')->label(__('console.common.customer'))
                    ->relationship('customer', 'name')->searchable()->preload(),
                Filter::make('in_progress')
                    ->label(__('console.projects.in_progress'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('takes', fn (Builder $takes): Builder => $takes
                        ->whereIn('status', array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal())))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
