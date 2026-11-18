<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Exceptions\Admin\AdminChangeDeniedException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * System › Admins **Delete** (D-086; super admin only): removes an admin and its roles, with an audit entry
 * (email, roles, required reason). Never your own account, never the last super admin: both are refused here as
 * well as hidden in the console (UserPolicy), with the super admins locked so two deletes cannot race past it.
 */
final readonly class DeleteAdminUserAction
{
    public const string EVENT = 'admin_deleted';

    public function __construct(
        private RecordAdminActivityAction $recordActivity,
        private LockSuperAdminsAction $lockSuperAdmins,
    ) {}

    public function execute(User $actor, User $admin, string $reason): void
    {
        if ($actor->is($admin)) {
            throw AdminChangeDeniedException::ownAccount();
        }

        DB::transaction(function () use ($actor, $admin, $reason): void {
            $superAdmins = $this->lockSuperAdmins->execute();

            if (in_array($admin->id, $superAdmins, true) && count($superAdmins) <= 1) {
                throw AdminChangeDeniedException::lastSuperAdmin();
            }

            $properties = [
                'resource' => $admin->getTable(),
                'record' => (string) $admin->id,
                'email' => $admin->email,
                'roles' => $admin->getRoleNames()->sort()->implode(','),
                'reason' => trim($reason),
            ];

            $admin->syncRoles([]);
            $admin->delete();

            $this->recordActivity->execute($actor, self::EVENT, null, $properties);
        });
    }
}
