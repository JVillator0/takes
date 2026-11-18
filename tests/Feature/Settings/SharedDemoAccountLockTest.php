<?php

declare(strict_types=1);

use App\Actions\Auth\UpdateCustomerPasswordAction;
use App\Actions\Auth\UpdateCustomerProfileAction;
use App\DTOs\Auth\UpdateCustomerProfileDTO;
use App\Enums\Auth\DemoAccount;
use App\Enums\Auth\DenialReason;
use App\Models\Customer;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/*
| D-072: the seeded demo logins are public (P02 panel, README), so nobody
| may change their name, email or password; that would lock every other
| evaluator out. Denied with `demo_account_locked`.
*/

beforeEach(function (): void {
    $this->seed(DemoAccountsSeeder::class);
});

function sharedDemo(DemoAccount $account = DemoAccount::DemoCustomer): Customer
{
    return Customer::query()->where('email', $account->email())->sole();
}

it('denies a profile change on every shared demo login', function (DemoAccount $account): void {
    $customer = sharedDemo($account);

    $this->actingAs($customer, 'customer')
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => 'Hijacked', 'email' => 'attacker@example.com'], ['X-Inertia' => 'true'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('inertia.flash_data.denial.reason', DenialReason::DemoAccountLocked->value)
        ->assertSessionHas('inertia.flash_data.denial.message', "Shared demo accounts can't change their name, email or password. Register your own account to do that.");

    $customer->refresh();
    expect($customer->email)->toBe($account->email())
        ->and($customer->name)->toBe($account->customerName());
})->with(DemoAccount::cases());

it('denies a password change on a shared demo login, before validation', function (): void {
    $customer = sharedDemo();

    $this->actingAs($customer, 'customer')
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'hijacked-password',
            'password_confirmation' => 'hijacked-password',
        ])
        ->assertForbidden();

    expect(Hash::check('password', $customer->refresh()->password))->toBeTrue();
});

it('re-checks the lock in the actions for non-HTTP callers', function (): void {
    $customer = sharedDemo();

    expect(fn () => app(UpdateCustomerProfileAction::class)->execute($customer, new UpdateCustomerProfileDTO('Hijacked', 'attacker@example.com')))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateCustomerPasswordAction::class)->execute($customer, 'hijacked-password'))
        ->toThrow(AuthorizationException::class);

    $customer->refresh();
    expect($customer->email)->toBe(DemoAccount::DemoCustomer->email())
        ->and(Hash::check('password', $customer->password))->toBeTrue();
});

it('lets a regular customer change their own account through the actions', function (): void {
    $customer = Customer::factory()->create();

    app(UpdateCustomerProfileAction::class)->execute($customer, new UpdateCustomerProfileDTO('Ana López', 'ana@example.com'));
    app(UpdateCustomerPasswordAction::class)->execute($customer, 'new-password');

    $customer->refresh();
    expect($customer->name)->toBe('Ana López')
        ->and($customer->email)->toBe('ana@example.com')
        ->and(Hash::check('new-password', $customer->password))->toBeTrue();
});

it('shares updateAccount as denied for a shared demo login and allowed otherwise', function (): void {
    $this->actingAs(sharedDemo(), 'customer')
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.updateAccount.allowed', false)
            ->where('auth.can.updateAccount.reason', DenialReason::DemoAccountLocked->value));

    $this->actingAs(Customer::factory()->create(), 'customer')
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.can.updateAccount.allowed', true));
});

it('shares the lock and its 2-01 notice with both settings pages', function (string $route, string $component): void {
    $this->actingAs(sharedDemo(), 'customer')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('auth.can.updateAccount.allowed', false)
            ->where('auth.can.updateAccount.message', "Shared demo accounts can't change their name, email or password. Register your own account to do that."));
})->with([
    'profile' => ['profile.edit', 'settings/Profile'],
    'security' => ['security.edit', 'settings/Security'],
]);
