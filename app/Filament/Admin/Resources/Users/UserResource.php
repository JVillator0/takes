<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users;

use App\Enums\Auth\AdminRole;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Filament\Admin\Resources\Users\Schemas\UserForm;
use App\Filament\Admin\Resources\Users\Schemas\UserInfolist;
use App\Filament\Admin\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * System › Admins (spec 7 "Users (admins)", D-086): the console's admins and their roles. Authorized by UserPolicy:
 * the operator and the auditor read, only the super admin creates, edits and deletes, never deleting itself or
 * the last super admin. Writes go through App\Actions\Admin and are audited with a reason. Password hashes and
 * authenticator secrets are never shown: the list only says whether an authenticator app is set up.
 */
final class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'admins';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('console.admins.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('console.admins.plural');
    }

    /**
     * Console admins only (a `users` row holding one of the console roles), with their roles loaded.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return User::query()->role(AdminRole::values(), AdminRole::GUARD)->with('roles');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
