<?php

declare(strict_types=1);

use App\Models\Customer;

/*
| D-065: an ephemeral demo customer past `demo_expires_at` is logged out on
| its next request (lazy, no scheduler, never deleted).
*/

it('logs an expired demo customer out and sends them to sign in', function (): void {
    $customer = Customer::factory()->demo()->create(['demo_expires_at' => now()->subMinute()]);

    $this->actingAs($customer, 'customer')
        ->get(route('projects.index'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This demo account has expired. Register or try a new demo account.');

    $this->assertGuest('customer');
    expect(Customer::query()->find($customer->id))->not->toBeNull();
});

it('treats the exact expiry instant as expired', function (): void {
    $this->freezeTime();
    $customer = Customer::factory()->demo()->create(['demo_expires_at' => now()]);

    $this->actingAs($customer, 'customer')->get(route('projects.index'))->assertRedirect(route('login'));
});

it('lets an active demo customer through', function (): void {
    $customer = Customer::factory()->demo()->create();

    $this->actingAs($customer, 'customer')->get(route('projects.index'))->assertOk();

    $this->assertAuthenticatedAs($customer, 'customer');
});

it('never expires a regular customer', function (): void {
    $customer = Customer::factory()->create(['demo_expires_at' => now()->subDay()]);

    $this->actingAs($customer, 'customer')->get(route('projects.index'))->assertOk();
});
