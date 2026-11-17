<?php

declare(strict_types=1);

use App\Models\Customer;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the confirm-password page for a customer', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer')
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/ConfirmPassword'));
});

it('confirms the password against the customer guard', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer')
        ->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('auth.password_confirmed_at');
});

it('requires a signed-in customer', function (): void {
    $this->get(route('password.confirm'))->assertRedirect(route('login'));
});
