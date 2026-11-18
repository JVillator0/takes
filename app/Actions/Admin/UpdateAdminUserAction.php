<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\DTOs\Admin\AdminUserDTO;
use App\Enums\Auth\AdminRole;
use App\Exceptions\Admin\AdminChangeDeniedException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * System › Admins **Edit** (D-086; super admin only): name, email, roles and, when given, a new password, with an
 * audit entry (roles before and after, whether the password changed, the required reason). It runs under
 * LockSuperAdminsAction (the `super_admin` role row locked, members re-read), so taking the role from the last
 * super admin is refused even when two edits race.
 */
final readonly class UpdateAdminUserAction
{
    public const string EVENT = 'admin_updated';

    public function __construct(
        private RecordAdminActivityAction $recordActivity,
        private LockSuperAdminsAction $lockSuperAdmins,
    ) {}

    public function execute(User $actor, User $admin, AdminUserDTO $data): User
    {
        return DB::transaction(function () use ($actor, $admin, $data): User {
            $superAdmins = $this->lockSuperAdmins->execute();
            // Re-read under the lock: the page may have preloaded roles another edit has changed since.
            $admin->load('roles');
            $rolesBefore = $admin->getRoleNames()->sort()->values()->all();
            $losesSuperAdmin = in_array($admin->id, $superAdmins, true) && ! in_array(AdminRole::SuperAdmin, $data->roles, true);

            if ($losesSuperAdmin && count($superAdmins) <= 1) {
                throw AdminChangeDeniedException::lastSuperAdmin();
            }

            $admin->forceFill(['name' => $data->name, 'email' => $data->email]);

            if ($data->password !== null) {
                $admin->forceFill(['password' => $data->password]);
            }

            $admin->save();
            $admin->syncRoles($data->roleNames());

            $this->recordActivity->execute($actor, self::EVENT, $admin, [
                'resource' => $admin->getTable(),
                'record' => (string) $admin->id,
                'roles_before' => implode(',', $rolesBefore),
                'roles_after' => implode(',', $admin->getRoleNames()->sort()->values()->all()),
                'password_changed' => $data->password !== null,
                'reason' => $data->reason,
            ]);

            return $admin;
        });
    }
}
