<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

it('renders the sign-in page', function (): void {
    $this->get(route('login'))->assertOk();
});

it('signs a customer in on the customer guard, never on web', function (): void {
    $customer = Customer::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $customer->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($customer, 'customer');
    $this->assertGuest('web');
    $response->assertRedirect(route('projects.index', absolute: false));
});

it('does not sign an admin user in through the public login', function (): void {
    $admin = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('customer');
    $this->assertGuest('web');
});

it('rejects an invalid password', function (): void {
    $customer = Customer::factory()->create();

    $this->post(route('login.store'), [
        'email' => $customer->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest('customer');
});

it('logs a customer out', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest('customer');
});

it('rate limits sign-in attempts', function (): void {
    $customer = Customer::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$customer->email, '127.0.0.1'])), amount: 5);

    $this->post(route('login.store'), [
        'email' => $customer->email,
        'password' => 'wrong-password',
    ])->assertTooManyRequests();
});
