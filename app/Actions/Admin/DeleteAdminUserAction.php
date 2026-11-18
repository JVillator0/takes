<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Exceptions\Admin\AdminChangeDeniedException;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * System › Admins **Delete** (D-086; super admin only): removes an admin and its roles, with an audit entry
 * (email, roles, required reason). Refused: your own account and the last super admin (also hidden in the
 * console by UserPolicy; checked under LockSuperAdminsAction, so two deletes cannot race past it), and an admin
 * with history, a ledger adjustment (`ledger_entries.admin_id`, restrict FK) or an audit entry it caused: who did
 * what must stay readable, so such an admin keeps its row and loses access by having its roles removed.
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

            if ($this->hasHistory($admin)) {
                throw AdminChangeDeniedException::hasHistory();
            }

            $admin->load('roles');

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

    private function hasHistory(User $admin): bool
    {
        return LedgerEntry::query()->where('admin_id', $admin->id)->exists()
            || Activity::query()->where('causer_type', $admin->getMorphClass())->where('causer_id', $admin->id)->exists();
    }
}
