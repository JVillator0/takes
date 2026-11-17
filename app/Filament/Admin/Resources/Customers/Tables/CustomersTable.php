<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers\Tables;

use App\Enums\Auth\CustomerRole;
use App\Enums\Takes\TakeStatus;
use App\Filament\Admin\Resources\Customers\Actions\AdjustCreditsAction;
use App\Filament\Admin\Resources\Customers\Actions\RestrictCustomerAction;
use App\Filament\Admin\Support\Money;
use App\Models\Customer;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers (A09): role, available, on hold, lifetime spend, profit, takes, last active, joined. Money is exact
 * (D-042); available = balance − held (D-024). Default sort: last active, newest first.
 */
final class CustomersTable
{
    /** Available below $0.10 (A09 filter), in nanodollars. */
    public const int LOW_AVAILABLE_NANOS = 100_000_000;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('console.customers.customer'))
                    ->description(fn (Customer $record): string => $record->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('roles.name')->label(__('console.customers.role'))->badge()
                    ->color(fn (string $state): string => $state === CustomerRole::Restricted->value ? 'danger' : 'success'),
                TextColumn::make('available')
                    ->label(__('console.customers.available'))
                    ->state(fn (Customer $record): string => $record->available()->formatExact())
                    ->alignEnd(),
                TextColumn::make('held_nanos')->label(__('console.customers.on_hold'))
                    ->formatStateUsing(fn (int $state): string => Money::exact($state))
                    ->alignEnd()->sortable(),
                TextColumn::make('spend_nanos')->label(__('console.customers.lifetime_spend'))
                    ->formatStateUsing(fn (int|string $state): string => Money::exact($state))
                    ->alignEnd()->sortable(),
                TextColumn::make('profit')->label(__('console.customers.profit'))
                    ->state(fn (Customer $record): string => self::profit($record)->formatExact())
                    ->alignEnd(),
                TextColumn::make('takes_count')->label(__('console.customers.takes'))->alignEnd()->sortable(),
                TextColumn::make('last_active_at')->label(__('console.customers.last_active'))
                    ->since()->placeholder(__('console.customers.never'))->sortable(),
                TextColumn::make('created_at')->label(__('console.customers.joined'))->date('Y-m-d')->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->label(__('console.customers.filters.role'))
                    ->options(collect(CustomerRole::cases())->mapWithKeys(fn (CustomerRole $role): array => [$role->value => $role->value])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', $data['value']))
                        : $query),
                Filter::make('active_since')
                    ->label(__('console.customers.filters.active_since'))
                    ->schema([DatePicker::make('since')->label(__('console.customers.filters.active_since'))])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['since'] ?? null,
                        fn (Builder $inner, string $day): Builder => $inner->where('last_active_at', '>=', CarbonImmutable::parse($day, 'UTC')->startOfDay()),
                    )),
                Filter::make('low_available')
                    ->label(__('console.customers.filters.low_available'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereRaw('balance_nanos - held_nanos < ?', [self::LOW_AVAILABLE_NANOS])),
                Filter::make('in_progress')
                    ->label(__('console.customers.filters.in_progress'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('takes', fn (Builder $takes): Builder => $takes
                        ->whereIn('status', array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal())))),
            ])
            ->recordActions([
                ViewAction::make(),
                AdjustCreditsAction::make(),
                RestrictCustomerAction::make(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('last_active_at desc nulls last')->orderByDesc('customers.id'));
    }

    /**
     * Lifetime spend − provider cost, from the query's SQL sums.
     */
    public static function profit(Customer $customer): Nanodollars
    {
        return Nanodollars::fromInt(self::sum($customer, 'spend_nanos'))->minus(Nanodollars::fromInt(self::sum($customer, 'cost_nanos')));
    }

    private static function sum(Customer $customer, string $attribute): int|string
    {
        $value = $customer->getAttribute($attribute);

        return is_int($value) || is_string($value) ? $value : 0;
    }
}
