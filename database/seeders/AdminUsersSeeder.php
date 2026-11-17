<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Auth\AdminRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The first super admin and the read-only auditor (spec 7 issue 2, D-013, D-045), from `config/admin.php` (env).
 * An account is seeded only when both its email and password are set. Safe on every deploy.
 *
 * - Super admin: created once with its password and role; a later run never resets the password nor re-grants a
 *   role someone revoked on purpose (offboarding the env admin survives a redeploy).
 * - Auditor: a public demo login printed on /admin/login, so like the demo customers (D-072) every run re-asserts
 *   its password, clears any authenticator app and pins it to exactly the auditor role. Its email must differ
 *   from the super admin's (the run fails loudly otherwise).
 */
final class AdminUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminRolesSeeder::class);

        $this->refuseSharedEmail();
        $this->seedSuperAdmin();
        $this->seedAuditor();
    }

    /**
     * The auditor's password is public; one account must never be both.
     */
    private function refuseSharedEmail(): void
    {
        $superAdminEmail = config('admin.super_admin.email');
        $auditorEmail = config('admin.auditor.email');

        if (is_string($superAdminEmail) && $superAdminEmail !== '' && is_string($auditorEmail) && strcasecmp($superAdminEmail, $auditorEmail) === 0) {
            throw new RuntimeException('ADMIN_AUDITOR_EMAIL must differ from ADMIN_EMAIL: the auditor password is public.');
        }
    }

    private function seedSuperAdmin(): void
    {
        $credentials = $this->credentials('admin.super_admin');

        if ($credentials === null || User::query()->where('email', $credentials['email'])->exists()) {
            return;
        }

        $this->create($credentials)->assignRole(AdminRole::SuperAdmin->value);
    }

    private function seedAuditor(): void
    {
        $credentials = $this->credentials('admin.auditor');

        if ($credentials === null) {
            return;
        }

        $auditor = User::query()->where('email', $credentials['email'])->first() ?? $this->create($credentials);

        if (! Hash::check($credentials['password'], $auditor->password)) {
            $auditor->forceFill(['password' => $credentials['password']])->save();
        }

        // A shared login nobody may lock: no authenticator app (the set-up page refuses it too).
        if ($auditor->app_authentication_secret !== null || $auditor->app_authentication_recovery_codes !== null) {
            $auditor->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
        }

        // Exactly the auditor role: a public password must never carry anything more.
        if ($auditor->getRoleNames()->all() !== [AdminRole::Auditor->value]) {
            $auditor->syncRoles([AdminRole::Auditor->value]);
        }
    }

    /**
     * @return array{name: string, email: string, password: string}|null
     */
    private function credentials(string $configKey): ?array
    {
        $email = config("{$configKey}.email");
        $password = config("{$configKey}.password");
        $name = config("{$configKey}.name");

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            return null;
        }

        return ['name' => is_string($name) && $name !== '' ? $name : $email, 'email' => $email, 'password' => $password];
    }

    /**
     * @param  array{name: string, email: string, password: string}  $credentials
     */
    private function create(array $credentials): User
    {
        $admin = new User;
        $admin->forceFill([
            'name' => $credentials['name'],
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'email_verified_at' => now(),
        ])->save();

        return $admin;
    }
}
