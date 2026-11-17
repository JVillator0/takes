<?php

declare(strict_types=1);

use App\Enums\Auth\CustomerPermission;
use App\Enums\Auth\CustomerRole;
use App\Models\Customer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('creates the customer-guard permissions and roles from the enums', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::query()->where('guard_name', 'customer')->pluck('name')->sort()->values()->all())
        ->toBe(['ai.suggest', 'credits.purchase', 'projects.manage', 'takes.create'])
        ->and(Role::query()->pluck('guard_name', 'name')->all())
        ->toBe(['customer' => 'customer', 'restricted' => 'customer', 'showcase' => 'customer']);
});

it('grants the permission matrix from spec 3-01', function (CustomerRole $role, array $granted): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $customer = Customer::factory()->withRole($role)->create();

    foreach (CustomerPermission::cases() as $permission) {
        expect($customer->checkPermissionTo($permission->value))
            ->toBe(in_array($permission, $granted, true), "{$role->value} / {$permission->value}");
    }
})->with([
    'customer' => [CustomerRole::Customer, CustomerPermission::cases()],
    'restricted' => [CustomerRole::Restricted, [CustomerPermission::CreditsPurchase, CustomerPermission::ProjectsManage]],
    'showcase (read-only, D-072)' => [CustomerRole::Showcase, []],
]);

it('is idempotent and safe to run on every deploy', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $customer = Customer::factory()->withRole()->create();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::query()->count())->toBe(4)
        ->and(Role::query()->count())->toBe(3)
        ->and($customer->refresh()->hasRole('customer'))->toBeTrue()
        ->and($customer->checkPermissionTo('takes.create'))->toBeTrue();
});

it('re-syncs a role that drifted from the enum', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Role::findByName('restricted', 'customer')->givePermissionTo('takes.create');

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName('restricted', 'customer')->hasPermissionTo('takes.create'))->toBeFalse();
});
