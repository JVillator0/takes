<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Clusters\Studio\Resources\Takes\TakeResource;
use App\Models\Take;
use App\Queries\Takes\ListTakesNeedingAttentionQuery;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Str;

/**
 * Dashboard (A02) **Needs attention** (D-054): takes with a durable attention flag and stuck takes (D-053), read
 * from the database only (FI-11); columns Type, Customer, Reference, Since. A row opens the take view (A06), where
 * Reconcile now lives. Never polls (D-038).
 */
final class NeedsAttentionTable extends TableWidget
{
    use HasWidgetShield;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('console.dashboard.attention.heading'))
            ->description(__('console.dashboard.attention.description'))
            ->query(fn () => app(ListTakesNeedingAttentionQuery::class)->builder())
            ->columns([
                // A flagged take shows its reason; a take that is only stuck (D-053) shows "Stuck".
                TextColumn::make('type')
                    ->label(__('console.dashboard.attention.type'))
                    ->state(fn (Take $record): string => $record->attention_reason?->getLabel() ?? __('console.dashboard.attention.stuck'))
                    ->badge()
                    ->color(fn (Take $record): string => $record->attention_reason?->getColor() ?? 'warning'),
                TextColumn::make('customer.name')
                    ->label(__('console.common.customer'))
                    ->description(fn (Take $record): string => (string) $record->project?->name),
                TextColumn::make('id')
                    ->label(__('console.dashboard.attention.reference'))
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 10, '…'))
                    ->fontFamily('mono')
                    ->tooltip(fn (Take $record): string => $record->id),
                TextColumn::make('status')->label(__('console.takes.status'))->badge(),
                TextColumn::make('since')
                    ->label(__('console.dashboard.attention.since'))
                    ->state(fn (Take $record): mixed => $record->last_event_at ?? $record->submitted_at ?? $record->created_at)
                    ->dateTime('Y-m-d H:i:s \U\T\C'),
            ])
            ->recordUrl(fn (Take $record): ?string => TakeResource::canView($record) ? TakeResource::getUrl('view', ['record' => $record]) : null)
            ->recordActions([
                Action::make('open')
                    ->label(__('console.dashboard.attention.open'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Take $record): string => TakeResource::getUrl('view', ['record' => $record]))
                    ->visible(fn (Take $record): bool => TakeResource::canView($record)),
            ])
            ->emptyStateHeading(__('console.dashboard.attention.empty'))
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->defaultPaginationPageOption(10)
            ->paginated([10, 25]);
    }
}
