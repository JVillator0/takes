<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\Auth\AdminRole;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

/**
 * Create and edit an admin (D-086): account (name, email, password, required on create and optional on edit),
 * access (console roles) and the audit reason every access change carries (FI-08). The password field never
 * shows the stored hash; it is only ever a new value.
 */
final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('console.admins.account'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('console.admins.name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label(__('console.admins.email'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(User::class, 'email', ignoreRecord: true),
                    TextInput::make('password')
                        ->label(__('console.admins.password'))
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->rule(Password::defaults())
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation) => $operation === 'edit' ? __('console.admins.password_help') : null)
                        // Never filled from the record: the hash stays on the server.
                        ->formatStateUsing(fn (): null => null),
                ]),
            Section::make(__('console.admins.access'))
                ->schema([
                    CheckboxList::make('roles')
                        ->label(__('console.admins.roles'))
                        ->helperText(__('console.admins.roles_help'))
                        ->options(AdminRole::class)
                        ->required()
                        ->formatStateUsing(fn (?User $record): array => $record?->getRoleNames()->values()->all() ?? []),
                ]),
            Section::make(__('console.admins.audit'))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('console.admins.reason'))
                        ->helperText(__('console.admins.reason_help'))
                        ->required()
                        ->maxLength(500),
                ]),
        ])->columns(1);
    }
}
