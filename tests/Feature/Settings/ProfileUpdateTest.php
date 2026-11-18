<?php

declare(strict_types=1);

use App\Models\Customer;

it('renders the profile page', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer')
        ->get(route('profile.edit'))
        ->assertOk();
});

it('updates the customer resolved through the customer guard (D-032)', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->patch(route('profile.update'), [
            'name' => 'Ana López',
            'email' => 'ANA@example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $customer->refresh();

    expect($customer->name)->toBe('Ana López')
        ->and($customer->email)->toBe('ana@example.com')
        ->and($customer->email_verified_at)->toBeNull();
});

it('rejects an email used by another customer', function (): void {
    Customer::factory()->create(['email' => 'taken@example.com']);
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $customer->name,
            'email' => 'taken@example.com',
        ])
        ->assertSessionHasErrors('email');
});

it('keeps the email when it is unchanged', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->patch(route('profile.update'), [
            'name' => 'Renamed',
            'email' => $customer->email,
        ])
        ->assertSessionHasNoErrors();

    expect($customer->refresh()->name)->toBe('Renamed');
});

it('refuses to move a customer onto a demo account address (D-072, #111)', function (string $email): void {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $customer->name,
            'email' => $email,
        ])
        ->assertSessionHasErrors('email');

    expect($customer->refresh()->email)->not->toBe(strtolower($email));
})->with([
    'showcase' => ['showcase@example.com'],
    'demo customer, upper case' => ['DEMO@example.com'],
]);
