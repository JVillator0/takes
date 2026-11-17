<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers\Schemas;

use App\Filament\Admin\Resources\Customers\Tables\CustomersTable;
use App\Filament\Admin\Support\Money;
use App\Models\Customer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A05 header stats: Available, On hold, Lifetime spend, Profit generated, Takes (with failed). Exact amounts
 * (D-042), the same model as the public app (D-024).
 */
final class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(5)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('available')->label(__('console.customers.available'))
                        ->state(fn (Customer $record): string => $record->available()->formatExact())->size('lg')->weight('bold'),
                    TextEntry::make('held_nanos')->label(__('console.customers.on_hold'))
                        ->formatStateUsing(fn (int $state): string => Money::exact($state))->size('lg')->weight('bold'),
                    TextEntry::make('spend_nanos')->label(__('console.customers.lifetime_spend'))
                        ->state(fn (Customer $record): string => Money::exact(is_numeric($record->getAttribute('spend_nanos')) ? (string) $record->getAttribute('spend_nanos') : 0))
                        ->size('lg')->weight('bold'),
                    TextEntry::make('profit')->label(__('console.customers.profit'))
                        ->state(fn (Customer $record): string => CustomersTable::profit($record)->formatExact())->size('lg')->weight('bold'),
                    TextEntry::make('takes_count')->label(__('console.customers.takes'))
                        ->state(fn (Customer $record): string => __('console.customers.takes_value', [
                            'takes' => (int) $record->getAttribute('takes_count'),
                            'failed' => (int) $record->getAttribute('failed_takes_count'),
                        ]))->size('lg')->weight('bold'),
                    TextEntry::make('email')->label(__('console.customers.customer')),
                    TextEntry::make('roles.name')->label(__('console.customers.role'))->badge(),
                    TextEntry::make('balance_nanos')->label(__('console.customers.balance'))
                        ->formatStateUsing(fn (int $state): string => Money::exact($state).' ('.Money::nanos($state).')'),
                    TextEntry::make('last_active_at')->label(__('console.customers.last_active'))->dateTime('Y-m-d H:i:s \U\T\C')->placeholder(__('console.customers.never')),
                    TextEntry::make('created_at')->label(__('console.customers.joined'))->dateTime('Y-m-d H:i:s \U\T\C'),
                ]),
        ]);
    }
}
