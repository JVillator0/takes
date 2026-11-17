<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Filament\Admin\Auth\SetUpRequiredMultiFactorAuthentication;
use App\Http\Middleware\EnsureAdminMultiFactorAuthentication;
use App\Models\User;
use Database\Seeders\AdminRolesSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

// Spec 7 issue 2 (D-012, D-013): /admin lets in only `users` rows holding a console role, on the `web` guard, with
// an authenticator app for every role but the read-only auditor.

beforeEach(function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('sends a guest to the console sign-in', function (): void {
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/shield/roles')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertOk();
});

it('refuses a users row that holds no console role', function (): void {
    $this->actingAs(User::factory()->withAppAuthentication()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('asks every role but the auditor to set up an authenticator app first', function (AdminRole $role): void {
    $this->actingAs(User::factory()->admin($role)->create())
        ->get('/admin')
        ->assertRedirect('/admin/multi-factor-authentication/set-up');
})->with([
    'super admin' => [AdminRole::SuperAdmin],
    'operator' => [AdminRole::Operator],
]);

it('lets a role that requires MFA in once its app is set up', function (AdminRole $role): void {
    $this->actingAs(User::factory()->admin($role)->withAppAuthentication()->create())
        ->get('/admin')
        ->assertOk();
})->with([
    'super admin' => [AdminRole::SuperAdmin],
    'operator' => [AdminRole::Operator],
]);

it('lets the auditor in without an authenticator app', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())
        ->get('/admin')
        ->assertOk();
});

it('evaluates the panel MFA closure with the signed-in admin', function (): void {
    $panel = Filament::getPanel('admin');
    $auditor = User::factory()->admin(AdminRole::Auditor)->create();
    $auditorAndOperator = User::factory()->admin(AdminRole::Auditor)->create();
    $auditorAndOperator->assignRole(AdminRole::Operator->value);

    expect($panel->isMultiFactorAuthenticationRequired())->toBeTrue();

    $this->actingAs($auditor);
    expect($panel->isMultiFactorAuthenticationRequired())->toBeFalse();

    $this->actingAs($auditorAndOperator);
    expect($panel->isMultiFactorAuthenticationRequired())->toBeTrue();
});

it('signs an operator in with the password and then the app code, never with the password alone', function (): void {
    $operator = User::factory()->admin(AdminRole::Operator)->withAppAuthentication()->create([
        'email' => 'operator@example.com',
    ]);

    $login = Livewire::test(Login::class)
        ->set('data.email', 'operator@example.com')
        ->set('data.password', 'password')
        ->call('authenticate');
    expect(Filament::auth()->check())->toBeFalse();

    $login->set('data.multiFactor.app.code', '000000')->call('authenticate')->assertHasErrors();
    expect(Filament::auth()->check())->toBeFalse();

    $login->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($operator))->call('authenticate');
    expect(Filament::auth()->id())->toBe($operator->id);
});

it('signs the auditor in with the password alone', function (): void {
    $auditor = User::factory()->admin(AdminRole::Auditor)->create(['email' => 'auditor@example.com']);

    Livewire::test(Login::class)
        ->set('data.email', 'auditor@example.com')
        ->set('data.password', 'password')
        ->call('authenticate');

    expect(Filament::auth()->id())->toBe($auditor->id);
});

it('says which environment it runs in on every page', function (): void {
    config(['admin.environment_label' => 'production']);

    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('PRODUCTION');
});

it('shows the auditor credentials on the sign-in page only while the demo panel is on', function (): void {
    config([
        'admin.auditor.email' => 'auditor@example.com',
        'admin.auditor.password' => 'demo-auditor-password',
        'takes.demo.enabled' => false,
    ]);

    $this->get('/admin/login')->assertOk()->assertDontSee('demo-auditor-password');

    config(['takes.demo.enabled' => true]);

    $this->get('/admin/login')->assertOk()->assertSee('auditor@example.com')->assertSee('demo-auditor-password');

    config(['admin.auditor.password' => null]);

    $this->get('/admin/login')->assertOk()->assertDontSee('auditor@example.com');
});

it('refuses the MFA set-up page to the auditor, so the shared login cannot be locked', function (): void {
    $auditor = User::factory()->admin(AdminRole::Auditor)->create();
    $this->actingAs($auditor);

    $this->get('/admin/multi-factor-authentication/set-up')->assertRedirect('/admin');
    Livewire::test(SetUpRequiredMultiFactorAuthentication::class)->assertRedirect('/admin')->assertDontSee('authenticator');

    expect($auditor->refresh()->app_authentication_secret)->toBeNull();
});

it('still shows the MFA set-up page to an operator without an app', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Operator)->create())
        ->get('/admin/multi-factor-authentication/set-up')
        ->assertOk();
});

it('fails closed when the MFA middleware runs without a current panel', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());
    Filament::setCurrentPanel(null);

    try {
        $result = app(EnsureAdminMultiFactorAuthentication::class)->handle(request(), fn (): string => 'passed');
    } catch (Throwable) {
        $result = 'refused';
    }

    expect($result)->not->toBe('passed');
});
