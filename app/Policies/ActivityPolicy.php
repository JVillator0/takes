<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * The console's audit (System › Activity log): read-only. The resource has no create, edit or delete page and no
 * seeded role holds a write permission on it. Registered in AdminPanelProvider (a vendor model is outside
 * Laravel's policy discovery).
 */
final class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Activity');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can('View:Activity');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
