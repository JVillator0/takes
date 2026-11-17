<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

it('requires a recent password confirmation', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer')
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));
});

it('renders the password settings without two-factor or passkeys', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->has('passwordRules')
            ->missing('canManageTwoFactor')
            ->missing('passkeys'));
});

it('updates the password', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->from(route('profile.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect(Hash::check('new-password', $customer->refresh()->password))->toBeTrue();
});

it('requires the current customer password', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->from(route('profile.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasErrors('current_password');
});
