<?php

declare(strict_types=1);

use App\Enums\Auth\AdminPermission;
use App\Enums\Auth\AdminRole;
use App\Filament\Admin\Audit\AdminAuditResource;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles;
use Database\Seeders\AdminRolesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
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
        ->toContain('ViewAny:Take', 'View:Take', 'ViewAny:Project', 'ViewAny:WebhookDelivery')
        ->toContain('View:TakeStatsOverview', 'View:WebhookDeliveryStatsOverview')
        ->and(array_filter($permissions, fn (string $permission): bool => ! str_starts_with($permission, 'View')))->toBe([])
        ->and(Permission::query()->whereIn('name', ['Create:Activity', 'Create:Take', 'Update:Take', 'Delete:Project'])->exists())->toBeFalse();
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

it('keeps roles read-only for every role, the super admin included', function (AdminRole $role): void {
    $this->actingAs(User::factory()->admin($role)->withAppAuthentication()->create());
    $auditorRole = Role::findByName(AdminRole::Auditor->value, AdminRole::GUARD);

    Livewire::test(ListRoles::class)->assertOk()->assertActionHidden('create');

    $this->get('/admin/shield/roles/create')->assertForbidden();
    $this->get('/admin/shield/roles/'.$auditorRole->id.'/edit')->assertForbidden();
    $this->get('/admin/shield/roles/'.$auditorRole->id)->assertOk();
    expect(auth()->user()?->can('delete', $auditorRole))->toBeFalse()
        ->and(Permission::query()->whereIn('name', ['Create:Role', 'Update:Role', 'Delete:Role'])->exists())->toBeFalse();
})->with([
    'auditor' => [AdminRole::Auditor],
    'operator' => [AdminRole::Operator],
    'super admin' => [AdminRole::SuperAdmin],
]);

it('refuses an empty cluster by URL', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::SuperAdmin)->withAppAuthentication()->create())
        ->get('/admin/billing')
        ->assertForbidden();
});

it('keeps the audit read-only, even for the super admin', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::SuperAdmin)->withAppAuthentication()->create())
        ->get('/admin/activitylogs')
        ->assertOk();

    expect(Filament::getPanel('admin')->getResources())->toContain(AdminAuditResource::class)
        ->and(AdminAuditResource::hasPage('create'))->toBeFalse()
        ->and(AdminAuditResource::hasPage('edit'))->toBeFalse();
});

it('lists the sidebar in the spec order and hides what the admin may not open', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder(['Dashboard', 'Operations', 'Studio', 'Providers', 'System', 'Roles', 'Activity log'])
        // An empty cluster hides itself until a page or resource lands in it.
        ->assertDontSee('Billing');
});

it('creates the admin roles from the deploy seeder', function (): void {
    Role::query()->where('guard_name', AdminRole::GUARD)->delete();

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::query()->where('guard_name', AdminRole::GUARD)->pluck('name')->sort()->values()->all())
        ->toBe(['auditor', 'operator', 'super_admin'])
        ->and(permissionsOf(AdminRole::Operator))->toContain(...AdminPermission::values())
        ->and(permissionsOf(AdminRole::Auditor))->toContain('ViewAny:Role', 'ViewAny:Activity');
});
