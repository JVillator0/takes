<?php

declare(strict_types=1);

use App\Enums\Auth\DenialReason;
use App\Http\Middleware\EnsureDemoAccountActive;
use App\Http\Middleware\UseCustomerGuard;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
| D-012 / D-032: customers live on the `customer` guard, admins on `web`
| (the default). Every customer route runs the `customer` middleware group.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

dataset('customer pages', [
    'dashboard' => 'dashboard',
    'profile settings' => 'profile.edit',
    'appearance settings' => 'appearance.edit',
]);

it('redirects guests to the sign-in page', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('customer pages');

it('rejects an admin (web) session on customer routes', function (string $route): void {
    $this->actingAs(User::factory()->create(), 'web')
        ->get(route($route))
        ->assertRedirect(route('login'));
})->with('customer pages');

it('never authenticates a customer on the web guard', function (): void {
    $customer = Customer::factory()->withRole()->create();

    $this->actingAs($customer, 'customer')->get(route('dashboard'))->assertOk();

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('resolves the customer in shared props on every customer page (D-032)', function (string $route): void {
    $customer = Customer::factory()->withRole()->create();

    $this->actingAs($customer, 'customer')
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.customer.id', $customer->id)
            ->where('auth.can.createProject.allowed', true));
})->with('customer pages');

it('makes the customer guard the request default inside the customer group', function (): void {
    $customer = Customer::factory()->withRole()->create();

    Route::middleware(['web', 'customer'])->get('/_test/who', fn (Request $request): array => [
        'default_guard' => Auth::getDefaultDriver(),
        'user_id' => $request->user()?->getAuthIdentifier(),
        'user_type' => $request->user() === null ? null : $request->user()::class,
        'can_create_project' => Gate::allows('create', Project::class),
    ]);

    $this->actingAs($customer, 'customer')
        ->getJson('/_test/who')
        ->assertExactJson([
            'default_guard' => 'customer',
            'user_id' => $customer->id,
            'user_type' => Customer::class,
            'can_create_project' => true,
        ]);
});

it('keeps web as the default guard outside the customer group', function (): void {
    expect(config('auth.defaults.guard'))->toBe('web');

    Route::middleware('web')->get('/_test/default', fn (): string => Auth::getDefaultDriver());

    $this->get('/_test/default')->assertSee('web');
});

it('registers the customer middleware aliases and group', function (): void {
    $kernel = app(Kernel::class);

    expect($kernel->getMiddlewareAliases())->toMatchArray([
        'customer.guard' => UseCustomerGuard::class,
        'customer.demo' => EnsureDemoAccountActive::class,
    ])->and($kernel->getMiddlewareGroups()['customer'] ?? null)->toBe(['customer.guard', 'auth:customer', 'customer.demo']);
});

it('sends a policy denial back to the Inertia page with its reason code', function (): void {
    $customer = Customer::factory()->restricted()->create();

    Route::middleware(['web', 'customer'])->post('/_test/deny', function (): void {
        Gate::authorize('test-denial');
    });
    Gate::define('test-denial', fn (Customer $customer) => DenialReason::AccountRestricted->deny());

    $this->actingAs($customer, 'customer')
        ->from(route('dashboard'))
        ->post('/_test/deny', [], ['X-Inertia' => 'true'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('inertia.flash_data.denial.reason', 'account_restricted')
        // An error too, so the form takes Inertia's error path, not success.
        ->assertSessionHasErrors(['denial' => 'Your account is restricted. Email support@example.com for help.']);
});
