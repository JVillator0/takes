<?php

declare(strict_types=1);

use App\Enums\Auth\AdminPermission;
use App\Enums\Auth\AdminRole;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles;
use Database\Seeders\AdminRolesSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Rmsramos\Activitylog\Resources\Activitylog\ActivitylogResource;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Spec 7 issue 2 / A08: three console roles on the `web` guard. The auditor holds view permissions only; the
// operator adds the custom write permissions; the super admin passes every check through Gate::before.

beforeEach(function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * @return list<string>
 */
function permissionsOf(AdminRole $role): array
{
    return Role::findByName($role->value, AdminRole::GUARD)->permissions->pluck('name')->sort()->values()->all();
}

it('seeds the roles on the web guard and can run again without changing them', function (): void {
    $before = [permissionsOf(AdminRole::Operator), permissionsOf(AdminRole::Auditor), Permission::query()->count()];

    $this->seed(AdminRolesSeeder::class);

    expect(Role::query()->where('guard_name', 'web')->pluck('name')->sort()->values()->all())
        ->toBe(['auditor', 'operator', 'super_admin'])
        ->and([permissionsOf(AdminRole::Operator), permissionsOf(AdminRole::Auditor), Permission::query()->count()])
        ->toBe($before);
});

it('gives the auditor view permissions only', function (): void {
    $permissions = permissionsOf(AdminRole::Auditor);

    expect($permissions)->toContain('ViewAny:Role', 'View:Role', 'ViewAny:Activity', 'View:Activity')
        ->and(array_filter($permissions, fn (string $permission): bool => ! str_starts_with($permission, 'View')))->toBe([])
        ->and(Permission::query()->whereIn('name', ['Create:Activity', 'Update:Activity', 'Delete:Activity'])->exists())->toBeFalse();
});

it('gives the operator the view permissions plus every custom write permission', function (): void {
    expect(permissionsOf(AdminRole::Operator))
        ->toContain('ViewAny:Role', 'ViewAny:Activity', ...AdminPermission::values())
        ->not->toContain('Create:Role', 'Update:Role', 'Delete:Role');
});

it('lets the super admin pass every ability without holding a permission', function (): void {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $auditor = User::factory()->admin(AdminRole::Auditor)->create();

    expect(permissionsOf(AdminRole::SuperAdmin))->toBe([])
        ->and($superAdmin->can(AdminPermission::AdjustCreditsCustomer->value))->toBeTrue()
        ->and($superAdmin->can('Create:Role'))->toBeTrue()
        ->and($auditor->can(AdminPermission::AdjustCreditsCustomer->value))->toBeFalse()
        ->and($auditor->can('Create:Role'))->toBeFalse();
});

it('shows the auditor and the operator no write action on roles, and the super admin the create action', function (AdminRole $role, bool $canCreate): void {
    $this->actingAs(User::factory()->admin($role)->withAppAuthentication()->create());

    $list = Livewire::test(ListRoles::class)->assertOk();

    $canCreate ? $list->assertActionVisible('create') : $list->assertActionHidden('create');
})->with([
    'auditor' => [AdminRole::Auditor, false],
    'operator' => [AdminRole::Operator, false],
    'super admin' => [AdminRole::SuperAdmin, true],
]);

it('keeps the audit read-only, even for the super admin', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::SuperAdmin)->withAppAuthentication()->create())
        ->get('/admin/activitylogs')
        ->assertOk();

    expect(Filament::getPanel('admin')->getResources())->toContain(ActivitylogResource::class)
        ->and(ActivitylogResource::hasPage('create'))->toBeFalse()
        ->and(ActivitylogResource::hasPage('edit'))->toBeFalse();
});

it('lists the sidebar in the spec order and hides what the admin may not open', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder(['Dashboard', 'System', 'Roles', 'Activity Log'])
        // Empty clusters hide themselves until a page or resource lands in them.
        ->assertDontSee('Studio')
        ->assertDontSee('Billing');
});
