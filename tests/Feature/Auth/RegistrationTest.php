<?php

declare(strict_types=1);

use App\Enums\Auth\CustomerRole;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('renders the registration page', function (): void {
    $this->get(route('register'))->assertOk();
});

it('registers a customer with the customer role and signs them in, with no verification step', function (): void {
    $response = $this->post(route('register.store'), [
        'name' => 'Ana López',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $customer = Customer::query()->where('email', 'ana@example.com')->sole();

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($customer, 'customer');
    expect($customer->hasRole(CustomerRole::Customer->value))->toBeTrue()
        ->and($customer->email_verified_at)->toBeNull()
        ->and(User::query()->count())->toBe(0);

    // D-030: straight into the app, no verification notice in between.
    $this->get(route('dashboard'))->assertOk();
});

it('rejects an email that already belongs to a customer', function (): void {
    Customer::factory()->create(['email' => 'ana@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Ana López',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('customer');
});

it('lets an admin email register as a separate customer account', function (): void {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Ana López',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    expect(Customer::query()->where('email', 'ana@example.com')->exists())->toBeTrue();
});
