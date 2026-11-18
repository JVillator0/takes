<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\Auth\AdminRole;
use App\Models\User;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
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
                        // Unique as it is saved: AdminUserDTO lowercases the email, so compare case-insensitively.
                        ->rule(fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (is_string($value) && self::emailTaken($value, $record)) {
                                $fail(__('console.admins.denied.email_taken'));
                            }
                        }),
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

    /**
     * Whether another `users` row already has this email, compared as AdminUserDTO stores it (trimmed, lowercased)
     * against the stored value lowercased, so a mixed-case duplicate is a form error, not a unique-index 500.
     */
    public static function emailTaken(string $email, ?User $ignore = null): bool
    {
        return User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->when($ignore !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignore?->getKey()))
            ->exists();
    }
}
