<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers;

use App\Filament\Admin\Concerns\ReadsWithShieldPermissions;
use App\Filament\Admin\Resources\Customers\Pages\ListCustomers;
use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Admin\Resources\Customers\RelationManagers\LedgerEntriesRelationManager;
use App\Filament\Admin\Resources\Customers\RelationManagers\TakesRelationManager;
use App\Filament\Admin\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Admin\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use App\Queries\Customers\ListCustomersWithMoneyQuery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers (A05 view, A09 table): read-only except the two audited actions, Adjust credits and
 * Restrict/Unrestrict, each behind its own permission (AdminPermission).
 */
final class CustomerResource extends Resource
{
    use ReadsWithShieldPermissions;

    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('console.customers.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('console.customers.plural');
    }

    /**
     * @return Builder<Customer>
     */
    public static function getEloquentQuery(): Builder
    {
        return app(ListCustomersWithMoneyQuery::class)->builder()->with('roles');
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LedgerEntriesRelationManager::class,
            TakesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }
}
