<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\DTOs\Admin\AdminUserDTO;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * System › Admins **Create** (spec 7 Resources, D-086; super admin only): a `users` row on the `web` guard with its
 * console roles, its email marked verified (an admin vouches for it, like AdminUsersSeeder), and an audit entry
 * with the roles and the required reason. The new admin sets up an authenticator app at first sign-in (D-013),
 * unless an auditor only.
 */
final readonly class CreateAdminUserAction
{
    public const string EVENT = 'admin_created';

    public function __construct(private RecordAdminActivityAction $recordActivity) {}

    public function execute(User $actor, AdminUserDTO $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $admin = new User;
            $admin->forceFill([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                'email_verified_at' => now(),
            ])->save();

            $admin->syncRoles($data->roleNames());

            $this->recordActivity->execute($actor, self::EVENT, $admin, [
                'resource' => $admin->getTable(),
                'record' => (string) $admin->id,
                'roles' => implode(',', $data->roleNames()),
                'reason' => $data->reason,
            ]);

            return $admin;
        });
    }
}
