<?php

declare(strict_types=1);

use App\Models\Customer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shares the customer with money as server-formatted strings only (D-023, D-024)', function (): void {
    // Demo dataset at 12:02: balance $10.64754375, on hold $0.3139625, available $10.3336.
    $customer = Customer::factory()->withRole()->withCachedBalance(10_647_543_750, 313_962_500)->create();

    $this->actingAs($customer, 'customer')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.customer', [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'available' => '$10.3336',
            'on_hold' => '$0.3140',
        ]));
});

it('shares abilities with reason codes and copy', function (): void {
    $customer = Customer::factory()->restricted()->create();

    $this->actingAs($customer, 'customer')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.createProject', ['allowed' => true, 'reason' => null, 'message' => null])
            ->where('can.purchaseCredits', ['allowed' => true, 'reason' => null, 'message' => null]));
});

it('shares no customer and no abilities with guests', function (): void {
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.customer', null)
            ->where('can', null));
});
