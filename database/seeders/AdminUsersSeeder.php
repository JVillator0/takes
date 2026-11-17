<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Auth\AdminRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The first super admin and the read-only auditor (spec 7 issue 2, D-013, D-045), from `config/admin.php` (env).
 * An account is seeded only when both its email and password are set, and its password is written only when it
 * is created, so re-running never resets a password someone changed. Safe on every deploy.
 */
final class AdminUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminRolesSeeder::class);

        $this->seedAdmin(AdminRole::SuperAdmin, 'admin.super_admin');
        $this->seedAdmin(AdminRole::Auditor, 'admin.auditor');
    }

    private function seedAdmin(AdminRole $role, string $configKey): void
    {
        $email = config("{$configKey}.email");
        $password = config("{$configKey}.password");
        $name = config("{$configKey}.name");

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            return;
        }

        $admin = User::query()->where('email', $email)->first();

        if ($admin === null) {
            $admin = new User;
            $admin->forceFill([
                'name' => is_string($name) && $name !== '' ? $name : $role->value,
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
            ])->save();
        }

        if (! $admin->hasRole($role->value)) {
            $admin->assignRole($role->value);
        }
    }
}
