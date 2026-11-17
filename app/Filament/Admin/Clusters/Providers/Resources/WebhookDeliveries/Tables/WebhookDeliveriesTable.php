<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Tables;

use App\Enums\Webhooks\WebhookOutcome;
use App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Actions\ViewPayloadAction;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\TakeResource;
use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Every verified delivery with its outcome (A11, D-027): duplicates and late events stay visible, which is the
 * evidence for the webhook requirements. Also the take view's relation manager table (A06), without the take
 * column.
 */
final class WebhookDeliveriesTable
{
    public static function configure(Table $table, bool $showTake = true): Table
    {
        return $table
            ->columns(array_values(array_filter([
                TextColumn::make('received_at')->label(__('console.webhooks.received_at'))->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('event_id')->label(__('console.webhooks.event_id'))->fontFamily('mono')
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 14, '…'))
                    ->tooltip(fn (WebhookDelivery $record): string => $record->event_id),
                TextColumn::make('type')->label(__('console.webhooks.type')),
                $showTake ? TextColumn::make('take_id')->label(__('console.webhooks.take'))->fontFamily('mono')->placeholder('-')
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 10, '…'))
                    ->url(fn (WebhookDelivery $record): ?string => $record->take_id === null ? null : TakeResource::getUrl('view', ['record' => $record->take_id])) : null,
                TextColumn::make('attempt')->label(__('console.webhooks.attempt'))->alignEnd(),
                TextColumn::make('outcome')->label(__('console.webhooks.outcome'))->badge(),
            ])))
            ->filters([
                SelectFilter::make('type')->label(__('console.webhooks.type'))
                    ->options(fn (): array => WebhookDelivery::query()->distinct()->orderBy('type')->pluck('type', 'type')->all()),
                SelectFilter::make('outcome')->label(__('console.webhooks.outcome'))->options(WebhookOutcome::class)->multiple(),
                Filter::make('received')
                    ->label(__('console.webhooks.received_at'))
                    ->schema([
                        DatePicker::make('from')->label(__('console.common.from')),
                        DatePicker::make('until')->label(__('console.common.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $inner, string $day): Builder => $inner->where('received_at', '>=', CarbonImmutable::parse($day, 'UTC')->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $inner, string $day): Builder => $inner->where('received_at', '<', CarbonImmutable::parse($day, 'UTC')->addDay()->startOfDay()))),
            ])
            ->recordActions([
                ViewPayloadAction::make(),
            ])
            ->defaultSort('received_at', 'desc');
    }
}
