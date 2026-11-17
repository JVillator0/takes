<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local setup: roles and permissions (also run on every deploy, D-068), a
 * local admin user, the demo accounts and the console admins (AdminUsersSeeder). Production seeds demo data by hand
 * with `db:seed --class=DemoAccountsSeeder --force`.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );

        $this->call(DemoAccountsSeeder::class);

        $this->call(AdminUsersSeeder::class);
    }
}
