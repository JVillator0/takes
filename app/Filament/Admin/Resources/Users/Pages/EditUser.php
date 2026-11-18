<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Actions\Admin\UpdateAdminUserAction;
use App\DTOs\Admin\AdminUserDTO;
use App\Enums\Auth\AdminRole;
use App\Exceptions\Admin\AdminChangeDeniedException;
use App\Filament\Admin\Resources\Users\Actions\DeleteAdminAction;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit an admin (super admin only, D-086): a confirmation modal, then UpdateAdminUserAction (audited with the
 * reason). Taking the super admin role from the last super admin is refused with a notification.
 */
final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAdminAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $record instanceof User, 403);

        try {
            /** @var array{name: string, email: string, password?: string|null, roles: list<string|AdminRole>, reason: string} $data */
            return app(UpdateAdminUserAction::class)->execute($actor, $record, AdminUserDTO::fromArray($data));
        } catch (AdminChangeDeniedException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading(__('console.admins.confirm_save'))
            ->modalDescription(__('console.admins.confirm_description'))
            ->action(fn () => $this->save());
    }

    protected function getRedirectUrl(): string
    {
        return UserResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
