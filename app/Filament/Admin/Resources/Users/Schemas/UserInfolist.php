<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\Auth\AdminRole;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One admin: name, email, roles, whether an authenticator app is set up (never the secret or recovery codes),
 * created and updated, in UTC.
 */
final class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('name')->label(__('console.admins.name')),
                    TextEntry::make('email')->label(__('console.admins.email')),
                    TextEntry::make('roles.name')->label(__('console.admins.roles'))->badge()
                        ->formatStateUsing(fn (string $state): string => AdminRole::tryFrom($state)?->getLabel() ?? $state)
                        ->color(fn (string $state): string => AdminRole::tryFrom($state)?->getColor() ?? 'gray'),
                    TextEntry::make('mfa')->label(__('console.admins.mfa'))->badge()
                        ->state(fn (User $record): string => UserInfolist::mfaLabel($record))
                        ->color(fn (User $record): string => $record->app_authentication_secret !== null ? 'success' : 'gray'),
                    TextEntry::make('created_at')->label(__('console.common.created'))->dateTime('Y-m-d H:i:s \U\T\C'),
                    TextEntry::make('updated_at')->label(__('console.common.updated'))->dateTime('Y-m-d H:i:s \U\T\C'),
                ]),
        ]);
    }

    public static function mfaLabel(User $admin): string
    {
        return $admin->app_authentication_secret !== null ? __('console.admins.mfa_set_up') : __('console.admins.mfa_not_set_up');
    }
}
