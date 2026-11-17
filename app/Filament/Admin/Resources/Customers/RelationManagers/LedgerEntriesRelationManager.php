<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers\RelationManagers;

use App\Enums\Billing\LedgerEntryType;
use App\Filament\Admin\Support\Money;
use App\Models\LedgerEntry;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A05 "Ledger" tab: the customer's real-money movements (holds are open usage records, D-024), nanodollars next
 * to dollars; append-only, newest first.
 */
final class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('console.customers.ledger.title');
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
                TextColumn::make('created_at')->label(__('console.customers.ledger.when'))->dateTime('Y-m-d H:i:s'),
                TextColumn::make('type')->label(__('console.customers.ledger.type'))->badge()
                    // An adjustment is a credit or a debit; its colour follows the sign.
                    ->color(fn (LedgerEntry $record): string => $record->type === LedgerEntryType::Adjustment
                        ? ($record->amount_nanos < 0 ? 'danger' : 'success')
                        : $record->type->getColor()),
                TextColumn::make('amount_nanos')->label(__('console.customers.ledger.amount_nanos'))
                    ->formatStateUsing(fn (int $state): string => Money::nanos($state))->alignEnd()->fontFamily('mono'),
                TextColumn::make('amount')->label(__('console.customers.ledger.amount'))
                    ->state(fn (Model $record): string => Money::exact((int) $record->getAttribute('amount_nanos')))->alignEnd(),
                TextColumn::make('reason')->label(__('console.customers.ledger.reason'))->placeholder('-')->wrap(),
            ])
            ->defaultSort('id', 'desc');
    }
}
