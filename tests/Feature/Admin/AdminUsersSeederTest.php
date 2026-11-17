<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Models\User;
use Database\Seeders\AdminUsersSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Hash;

// Spec 7 issue 2: the first super admin and the evaluator's auditor come from env, only when fully configured.

beforeEach(function (): void {
    config([
        'admin.super_admin' => ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'admin-secret'],
        'admin.auditor' => ['name' => 'Auditor', 'email' => 'auditor@example.com', 'password' => 'auditor-secret'],
    ]);
});

it('seeds the super admin and the auditor with their roles', function (): void {
    $this->seed(AdminUsersSeeder::class);

    $admin = User::query()->where('email', 'admin@example.com')->sole();
    $auditor = User::query()->where('email', 'auditor@example.com')->sole();

    expect($admin->hasRole(AdminRole::SuperAdmin->value))->toBeTrue()
        ->and($auditor->getRoleNames()->all())->toBe([AdminRole::Auditor->value])
        ->and(Hash::check('auditor-secret', $auditor->password))->toBeTrue();
});

it('seeds no account whose email or password is missing', function (): void {
    config(['admin.super_admin.password' => null, 'admin.auditor.email' => '']);

    $this->seed(AdminUsersSeeder::class);

    expect(User::query()->count())->toBe(0);
});

it('re-asserts the public auditor password and role on every run', function (): void {
    $this->seed(AdminUsersSeeder::class);
    $auditor = User::query()->where('email', 'auditor@example.com')->sole();
    $auditor->forceFill(['password' => 'drifted-secret'])->save();
    $auditor->syncRoles([]);

    $this->seed(AdminUsersSeeder::class);

    $auditor->refresh();
    expect(Hash::check('auditor-secret', $auditor->password))->toBeTrue()
        ->and($auditor->getRoleNames()->all())->toBe([AdminRole::Auditor->value]);
});

it('never resets the super admin password nor re-grants a revoked super admin role', function (): void {
    $this->seed(AdminUsersSeeder::class);
    $admin = User::query()->where('email', 'admin@example.com')->sole();
    $admin->forceFill(['password' => 'rotated-secret'])->save();
    $admin->removeRole(AdminRole::SuperAdmin->value);

    $this->seed(AdminUsersSeeder::class);

    $admin->refresh();
    expect(Hash::check('rotated-secret', $admin->password))->toBeTrue()
        ->and($admin->hasRole(AdminRole::SuperAdmin->value))->toBeFalse()
        ->and(User::query()->count())->toBe(2);
});

it('clears an authenticator app someone enrolled on the public auditor', function (): void {
    $this->seed(AdminUsersSeeder::class);
    $auditor = User::query()->where('email', 'auditor@example.com')->sole();
    $auditor->forceFill([
        'app_authentication_secret' => AppAuthentication::make()->generateSecret(),
        'app_authentication_recovery_codes' => ['code-1'],
    ])->save();

    $this->seed(AdminUsersSeeder::class);

    $auditor->refresh();
    expect($auditor->app_authentication_secret)->toBeNull()
        ->and($auditor->app_authentication_recovery_codes)->toBeNull();
});

it('pins the public auditor to exactly the auditor role', function (): void {
    $this->seed(AdminUsersSeeder::class);
    User::query()->where('email', 'auditor@example.com')->sole()->assignRole(AdminRole::Operator->value);

    $this->seed(AdminUsersSeeder::class);

    expect(User::query()->where('email', 'auditor@example.com')->sole()->getRoleNames()->all())->toBe([AdminRole::Auditor->value]);
});

it('fails loudly and seeds nobody when the auditor and super admin share an email', function (): void {
    config(['admin.auditor.email' => 'ADMIN@example.com']);

    expect(fn () => $this->seed(AdminUsersSeeder::class))->toThrow(RuntimeException::class, 'ADMIN_AUDITOR_EMAIL must differ');
    expect(User::query()->count())->toBe(0);
});
