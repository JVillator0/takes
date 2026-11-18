<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Enums\Auth\AdminRole;
use App\Filament\Admin\Resources\Users\Actions\DeleteAdminAction;
use App\Filament\Admin\Resources\Users\Schemas\UserInfolist;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * System › Admins list: name (marked "You" for the signed-in admin), email, roles, authenticator app set up or
 * not, created. Roles are eager-loaded by the resource query. Edit and Delete show only to the super admin
 * (UserPolicy); Delete never on your own row or the last super admin.
 */
final class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('console.admins.name'))
                    ->searchable()
                    ->description(fn (User $record) => Filament::auth()->id() === $record->id ? __('console.admins.you') : null),
                TextColumn::make('email')->label(__('console.admins.email'))->searchable(),
                TextColumn::make('roles.name')
                    ->label(__('console.admins.roles'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminRole::tryFrom($state)?->getLabel() ?? $state)
                    ->color(fn (string $state): string => AdminRole::tryFrom($state)?->getColor() ?? 'gray'),
                TextColumn::make('mfa')
                    ->label(__('console.admins.mfa'))
                    ->badge()
                    ->state(fn (User $record): string => UserInfolist::mfaLabel($record))
                    ->color(fn (User $record): string => $record->app_authentication_secret !== null ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label(__('console.common.created'))
                    ->dateTime('Y-m-d H:i:s \U\T\C')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(__('console.admins.roles'))
                    ->options(AdminRole::class)
                    ->query(fn (Builder $query, array $data): Builder => is_string($data['value'] ?? null) && $data['value'] !== ''
                        ? $query->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', $data['value']))
                        : $query),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAdminAction::make(),
            ])
            ->defaultSort('created_at');
    }
}
