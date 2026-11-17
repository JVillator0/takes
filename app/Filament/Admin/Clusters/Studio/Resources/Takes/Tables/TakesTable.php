<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Takes\Tables;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Enums\Takes\TakeSource;
use App\Enums\Takes\TakeStatus;
use App\Filament\Admin\Support\Money;
use App\Models\Take;
use App\Queries\Takes\ListStuckTakesQuery;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Studio › Takes (A09): the operational list. Money columns are the usage record's integer nanodollars, printed
 * exactly (D-042). "Stuck > 15 min" is the D-053 rule from ListStuckTakesQuery, also used by Needs attention.
 */
final class TakesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('console.takes.take'))
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 10, '…'))
                    ->fontFamily('mono')
                    ->tooltip(fn (Take $record): string => $record->id),
                TextColumn::make('customer.name')
                    ->label(__('console.takes.customer_project'))
                    ->description(fn (Take $record): string => (string) $record->project?->name),
                TextColumn::make('status')->label(__('console.takes.status'))->badge(),
                TextColumn::make('size')
                    ->label(__('console.takes.size_quality'))
                    ->formatStateUsing(fn (Take $record): string => $record->size->value.' · '.$record->quality->value),
                TextColumn::make('source')->label(__('console.takes.source'))->badge(),
                TextColumn::make('usageRecord.max_charge_nanos')
                    ->label(__('console.takes.estimate_max'))
                    ->formatStateUsing(fn (int $state): string => Money::exact($state))
                    ->alignEnd(),
                TextColumn::make('usageRecord.charge_nanos')
                    ->label(__('console.takes.charged'))
                    ->formatStateUsing(fn (int $state): string => Money::exact($state))
                    ->placeholder('-')
                    ->alignEnd(),
                TextColumn::make('usageRecord.provider_cost_nanos')
                    ->label(__('console.takes.provider_cost'))
                    ->formatStateUsing(fn (int $state): string => Money::exact($state))
                    ->placeholder('-')
                    ->alignEnd(),
                TextColumn::make('completed_at')
                    ->label(__('console.takes.duration'))
                    ->formatStateUsing(fn (Take $record): string => self::duration($record))
                    ->placeholder('-')
                    ->alignEnd(),
                TextColumn::make('created_at')
                    ->label(__('console.common.created'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('console.takes.status'))->options(TakeStatus::class)->multiple(),
                SelectFilter::make('source')->label(__('console.takes.source'))->options(TakeSource::class),
                SelectFilter::make('customer')->label(__('console.common.customer'))
                    ->relationship('customer', 'name')->searchable()->preload(),
                SelectFilter::make('project')->label(__('console.common.project'))
                    ->relationship('project', 'name')->searchable(),
                Filter::make('created')
                    ->label(__('console.takes.created_range'))
                    ->schema([
                        DatePicker::make('from')->label(__('console.common.from')),
                        DatePicker::make('until')->label(__('console.common.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::createdBetween($query, $data['from'] ?? null, $data['until'] ?? null)),
                Filter::make('stuck')
                    ->label(__('console.takes.stuck'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => app(ListStuckTakesQuery::class)->constrain($query)),
                SelectFilter::make('quality')->label(__('console.takes.quality'))
                    ->options(collect(TakeQuality::cases())->mapWithKeys(fn (TakeQuality $quality): array => [$quality->value => $quality->value])->all()),
                SelectFilter::make('size')->label(__('console.takes.size'))
                    ->options(collect(TakeSize::cases())->mapWithKeys(fn (TakeSize $size): array => [$size->value => $size->value])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Seconds from submission to completion; a take still running shows nothing.
     */
    public static function duration(Take $take): string
    {
        if ($take->submitted_at === null || $take->completed_at === null) {
            return '-';
        }

        return __('console.takes.duration_seconds', ['seconds' => (int) $take->submitted_at->diffInSeconds($take->completed_at)]);
    }

    /**
     * Days are UTC (billing days are UTC).
     *
     * @param  Builder<Take>  $query
     * @return Builder<Take>
     */
    private static function createdBetween(Builder $query, ?string $from, ?string $until): Builder
    {
        return $query
            ->when($from, fn (Builder $inner, string $day): Builder => $inner->where('created_at', '>=', CarbonImmutable::parse($day, 'UTC')->startOfDay()))
            ->when($until, fn (Builder $inner, string $day): Builder => $inner->where('created_at', '<', CarbonImmutable::parse($day, 'UTC')->addDay()->startOfDay()));
    }
}
