<?php

declare(strict_types=1);

use App\Actions\Admin\CreateAdminUserAction;
use App\Actions\Admin\DeleteAdminUserAction;
use App\Actions\Admin\RecordAdminActivityAction;
use App\Actions\Admin\UpdateAdminUserAction;
use App\DTOs\Admin\AdminUserDTO;
use App\Enums\Auth\AdminRole;
use App\Exceptions\Admin\AdminChangeDeniedException;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\LedgerEntry;
use App\Models\User;
use Database\Seeders\AdminRolesSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

// D-086 / spec 7 "Users (admins)": System › Admins. The operator and the auditor read; only the super admin
// creates, edits and deletes, never deleting itself or the last super admin; every write is audited with a reason.

beforeEach(function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
});

function consoleAdmin(AdminRole $role): User
{
    $factory = User::factory()->admin($role);

    return ($role === AdminRole::Auditor ? $factory : $factory->withAppAuthentication())->create();
}

it('seeds the Admins permissions: view for the operator and the auditor, writes for nobody', function (): void {
    foreach ([AdminRole::Operator, AdminRole::Auditor] as $role) {
        expect(Role::findByName($role->value, AdminRole::GUARD)->permissions->pluck('name')->all())
            ->toContain('ViewAny:User', 'View:User')
            ->not->toContain('Create:User', 'Update:User', 'Delete:User');
    }
});

it('authorizes Admins by role', function (AdminRole $role, bool $read, bool $write): void {
    $actor = consoleAdmin($role);
    $other = consoleAdmin(AdminRole::Operator);
    consoleAdmin(AdminRole::SuperAdmin); // so the other super admin is never the last one
    $this->actingAs($actor);

    expect(UserResource::canViewAny())->toBe($read)
        ->and(UserResource::canView($other))->toBe($read)
        ->and(UserResource::canCreate())->toBe($write)
        ->and(UserResource::canEdit($other))->toBe($write)
        ->and(UserResource::canDelete($other))->toBe($write)
        ->and(UserResource::canDeleteAny())->toBeFalse();
})->with([
    'super admin' => [AdminRole::SuperAdmin, true, true],
    'operator' => [AdminRole::Operator, true, false],
    'auditor' => [AdminRole::Auditor, true, false],
]);

it('lets the auditor list and view admins but shows no write action and refuses the write pages', function (): void {
    $other = consoleAdmin(AdminRole::Operator);
    $this->actingAs(consoleAdmin(AdminRole::Auditor));

    Livewire::test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$other])
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($other))
        ->assertActionHidden(TestAction::make('deleteAdmin')->table($other));

    Livewire::test(ViewUser::class, ['record' => $other->id])->assertOk()->assertActionHidden('deleteAdmin')->assertActionHidden('edit');
    $this->get('/admin/admins/create')->assertForbidden();
    $this->get('/admin/admins/'.$other->id.'/edit')->assertForbidden();
});

it('never shows a password hash or an authenticator secret, only whether an app is set up', function (): void {
    $other = consoleAdmin(AdminRole::Operator);
    $this->actingAs(consoleAdmin(AdminRole::SuperAdmin));

    $list = $this->get('/admin/admins')->assertOk()->assertSee('Set up');
    $view = $this->get('/admin/admins/'.$other->id)->assertOk();
    $edit = $this->get('/admin/admins/'.$other->id.'/edit')->assertOk();

    foreach ([$list, $view, $edit] as $response) {
        $response->assertDontSee($other->password, false)->assertDontSee((string) $other->app_authentication_secret, false);
    }
});

it('creates an admin with roles, audited with the reason', function (): void {
    $superAdmin = consoleAdmin(AdminRole::SuperAdmin);
    $this->actingAs($superAdmin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Ops Person',
            'email' => 'Ops@Example.com',
            'password' => 'a-long-password-1',
            'roles' => [AdminRole::Operator->value],
            'reason' => 'New on-call operator',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $admin = User::query()->where('email', 'ops@example.com')->sole();
    $entry = Activity::query()->where('event', CreateAdminUserAction::EVENT)->sole();

    expect($admin->getRoleNames()->all())->toBe(['operator'])
        ->and(Hash::check('a-long-password-1', $admin->password))->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and((int) $entry->causer_id)->toBe($superAdmin->id)
        ->and($entry->properties['roles'] ?? null)->toBe('operator')
        ->and($entry->properties['reason'] ?? null)->toBe('New on-call operator')
        ->and(json_encode($entry->properties))->not->toContain('a-long-password-1');
});

it('requires a password, a role and a reason to create an admin', function (): void {
    $this->actingAs(consoleAdmin(AdminRole::SuperAdmin));

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'X', 'email' => 'x@example.com', 'password' => '', 'roles' => [], 'reason' => ''])
        ->call('create')
        ->assertHasFormErrors(['password' => 'required', 'roles' => 'required', 'reason' => 'required']);

    expect(User::query()->where('email', 'x@example.com')->exists())->toBeFalse();
});

it('edits roles without touching the password when it is left empty, audited', function (): void {
    $this->actingAs(consoleAdmin(AdminRole::SuperAdmin));
    $other = consoleAdmin(AdminRole::Operator);
    $hash = $other->password;

    Livewire::test(EditUser::class, ['record' => $other->id])
        ->assertSchemaStateSet(['password' => null, 'roles' => [AdminRole::Operator]])
        ->fillForm(['roles' => [AdminRole::Auditor->value], 'reason' => 'Moved to read-only'])
        ->call('save')
        ->assertHasNoFormErrors();

    $entry = Activity::query()->where('event', UpdateAdminUserAction::EVENT)->sole();
    expect($other->refresh()->getRoleNames()->all())->toBe(['auditor'])
        ->and($other->password)->toBe($hash)
        ->and([$entry->properties['roles_before'], $entry->properties['roles_after'], $entry->properties['password_changed'], $entry->properties['reason']])
        ->toBe(['operator', 'auditor', false, 'Moved to read-only']);
});

it('refuses to take the role from the last super admin', function (): void {
    $superAdmin = consoleAdmin(AdminRole::SuperAdmin);
    $this->actingAs($superAdmin);

    Livewire::test(EditUser::class, ['record' => $superAdmin->id])
        ->fillForm(['roles' => [AdminRole::Operator->value], 'reason' => 'Oops'])
        ->call('save')
        ->assertNotified(__('console.admins.denied.last_super_admin'));

    expect($superAdmin->refresh()->hasRole(AdminRole::SuperAdmin->value))->toBeTrue()
        ->and(Activity::query()->where('event', UpdateAdminUserAction::EVENT)->exists())->toBeFalse();
});

it('deletes another admin with a reason, audited', function (): void {
    $superAdmin = consoleAdmin(AdminRole::SuperAdmin);
    $other = consoleAdmin(AdminRole::Operator);
    $this->actingAs($superAdmin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deleteAdmin')->table($other), ['reason' => 'Left the team'])
        ->assertHasNoFormErrors();

    $entry = Activity::query()->where('event', DeleteAdminUserAction::EVENT)->sole();
    expect(User::query()->whereKey($other->id)->exists())->toBeFalse()
        ->and((int) $entry->causer_id)->toBe($superAdmin->id)
        ->and([$entry->properties['email'], $entry->properties['roles'], $entry->properties['reason']])
        ->toBe([$other->email, 'operator', 'Left the team']);
});

it('never offers to delete your own account, and the action refuses it', function (): void {
    $superAdmin = consoleAdmin(AdminRole::SuperAdmin);
    consoleAdmin(AdminRole::SuperAdmin);
    $this->actingAs($superAdmin);

    expect(UserResource::canDelete($superAdmin))->toBeFalse();
    Livewire::test(ListUsers::class)->assertActionHidden(TestAction::make('deleteAdmin')->table($superAdmin));
    Livewire::test(ViewUser::class, ['record' => $superAdmin->id])->assertActionHidden('deleteAdmin');

    expect(fn () => app(DeleteAdminUserAction::class)->execute($superAdmin, $superAdmin, 'test'))
        ->toThrow(AdminChangeDeniedException::class, __('console.admins.denied.own_account'));
    expect(User::query()->whereKey($superAdmin->id)->exists())->toBeTrue();
});

it('never deletes the last super admin', function (): void {
    $last = consoleAdmin(AdminRole::SuperAdmin);
    // An operator with the write permission granted by hand still cannot remove the last super admin.
    $operator = consoleAdmin(AdminRole::Operator);
    $operator->givePermissionTo('Delete:User');
    $this->actingAs($operator);

    expect(UserResource::canDelete($last))->toBeFalse()
        ->and(fn () => app(DeleteAdminUserAction::class)->execute($operator, $last, 'test'))
        ->toThrow(AdminChangeDeniedException::class, __('console.admins.denied.last_super_admin'));

    expect($last->refresh()->hasRole(AdminRole::SuperAdmin->value))->toBeTrue();
});

it('refuses to delete an admin with ledger history and says to remove their roles instead', function (): void {
    $superAdmin = consoleAdmin(AdminRole::SuperAdmin);
    $operator = consoleAdmin(AdminRole::Operator);
    LedgerEntry::factory()->adjustment(2_000_000_000)->create(['admin_id' => $operator->id]);
    $this->actingAs($superAdmin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deleteAdmin')->table($operator), ['reason' => 'Left the team'])
        ->assertNotified(__('console.admins.denied.has_history'));

    expect(User::query()->whereKey($operator->id)->exists())->toBeTrue()
        ->and($operator->refresh()->hasRole(AdminRole::Operator->value))->toBeTrue()
        ->and(Activity::query()->where('event', DeleteAdminUserAction::EVENT)->exists())->toBeFalse();
});

it('refuses to delete an admin who caused audit entries', function (): void {
    $superAdmin = consoleAdmin(AdminRole::SuperAdmin);
    $operator = consoleAdmin(AdminRole::Operator);
    app(RecordAdminActivityAction::class)->execute($operator, 'credits_adjusted');

    expect(fn () => app(DeleteAdminUserAction::class)->execute($superAdmin, $operator, 'Left the team'))
        ->toThrow(AdminChangeDeniedException::class, __('console.admins.denied.has_history'));
    expect(User::query()->whereKey($operator->id)->exists())->toBeTrue();
});

it('refuses an email another admin already uses, whatever its case', function (): void {
    $this->actingAs(consoleAdmin(AdminRole::SuperAdmin));
    $other = User::factory()->admin(AdminRole::Operator)->create(['email' => 'ops@example.com']);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Dup', 'email' => 'OPS@Example.com', 'password' => 'a-long-password-1', 'roles' => [AdminRole::Auditor->value], 'reason' => 'x'])
        ->call('create')
        ->assertHasFormErrors(['email']);

    // Saving an admin with its own email (any case) is not a duplicate.
    Livewire::test(EditUser::class, ['record' => $other->id])
        ->fillForm(['email' => 'Ops@Example.com', 'reason' => 'Same address'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(User::query()->whereRaw('lower(email) = ?', ['ops@example.com'])->count())->toBe(1)
        ->and($other->refresh()->email)->toBe('ops@example.com');
});

it('lists only users that hold a console role', function (): void {
    $this->actingAs(consoleAdmin(AdminRole::Auditor));
    $admin = consoleAdmin(AdminRole::Operator);
    $noRole = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$admin])
        ->assertCanNotSeeTableRecords([$noRole]);
    $this->get('/admin/admins/'.$noRole->id)->assertNotFound();
});

it('updates and creates through the actions with normalised input', function (): void {
    $superAdmin = consoleAdmin(AdminRole::SuperAdmin);

    $admin = app(CreateAdminUserAction::class)->execute($superAdmin, AdminUserDTO::fromArray([
        'name' => '  Reader ',
        'email' => ' Reader@Example.com ',
        'password' => 'a-long-password-1',
        'roles' => ['auditor', 'auditor'],
        'reason' => ' read-only access ',
    ]));

    expect([$admin->name, $admin->email, $admin->getRoleNames()->all()])->toBe(['Reader', 'reader@example.com', ['auditor']]);
});
