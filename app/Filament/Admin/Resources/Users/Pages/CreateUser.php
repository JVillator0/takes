<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Actions\Admin\CreateAdminUserAction;
use App\DTOs\Admin\AdminUserDTO;
use App\Enums\Auth\AdminRole;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Create an admin (super admin only, D-086): a confirmation modal, then CreateAdminUserAction (audited with the
 * reason).
 */
final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        /** @var array{name: string, email: string, password?: string|null, roles: list<string|AdminRole>, reason: string} $data */
        return app(CreateAdminUserAction::class)->execute($actor, AdminUserDTO::fromArray($data));
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading(__('console.admins.confirm_create'))
            ->modalDescription(__('console.admins.confirm_description'))
            ->action(fn () => $this->create());
    }

    protected function getRedirectUrl(): string
    {
        return UserResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
