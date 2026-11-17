<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Models\User;
use Database\Seeders\AdminUsersSeeder;
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

it('never resets a seeded password when it runs again', function (): void {
    $this->seed(AdminUsersSeeder::class);
    User::query()->where('email', 'auditor@example.com')->sole()->forceFill(['password' => 'rotated-secret'])->save();

    $this->seed(AdminUsersSeeder::class);

    $auditor = User::query()->where('email', 'auditor@example.com')->sole();
    expect(Hash::check('rotated-secret', $auditor->password))->toBeTrue()
        ->and(User::query()->count())->toBe(2);
});
