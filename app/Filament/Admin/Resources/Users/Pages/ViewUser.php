<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\Actions\DeleteAdminAction;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        $admin = $this->getRecord();

        return $admin instanceof User ? $admin->name : __('console.admins.single');
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAdminAction::make(),
        ];
    }
}
