<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Actions;

use App\Actions\Admin\DeleteAdminUserAction;
use App\Exceptions\Admin\AdminChangeDeniedException;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * **Delete admin** (D-086): a confirmation modal with a required reason, then DeleteAdminUserAction (audited).
 * Shown only where UserPolicy allows it: the super admin, never on its own row or on the last super admin.
 */
final class DeleteAdminAction
{
    public static function make(): Action
    {
        return Action::make('deleteAdmin')
            ->label(__('filament-actions::delete.single.label'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize(fn (User $record): bool => UserResource::canDelete($record))
            ->requiresConfirmation()
            ->modalHeading(__('console.admins.delete.heading'))
            ->modalDescription(__('console.admins.delete.description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('console.admins.reason'))
                    ->helperText(__('console.admins.reason_help'))
                    ->required()
                    ->maxLength(500),
            ])
            ->action(function (User $record, array $data, Action $action): void {
                $actor = Filament::auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    app(DeleteAdminUserAction::class)->execute($actor, $record, (string) $data['reason']);
                } catch (AdminChangeDeniedException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('console.admins.delete.done'))->send();
                $action->redirect(UserResource::getUrl());
            });
    }
}
