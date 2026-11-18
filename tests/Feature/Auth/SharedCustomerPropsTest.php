<?php

declare(strict_types=1);

use App\Enums\Auth\DemoAccount;
use App\Models\Customer;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shares the customer with money as server-formatted strings only (D-023, D-024)', function (): void {
    // Demo dataset at 12:02: balance $10.64754375, on hold $0.3139625, available $10.33358125:
    // available rounds down and on hold up, as on the pill (D-021).
    $customer = Customer::factory()->withRole()->withCachedBalance(10_647_543_750, 313_962_500)->create();

    $this->actingAs($customer, 'customer')
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.customer', [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'available' => '$10.3335',
            'available_exact' => '$10.33358125',
            'on_hold' => '$0.3140',
            'on_hold_exact' => '$0.3139625',
        ]));
});

it('shares allowed abilities under auth.can', function (): void {
    $customer = Customer::factory()->restricted()->create();

    $this->actingAs($customer, 'customer')
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('can')
            ->where('auth.can.createProject', ['allowed' => true, 'reason' => null, 'message' => null])
            ->where('auth.can.purchaseCredits', ['allowed' => true, 'reason' => null, 'message' => null])
            ->where('auth.can.updateAccount', ['allowed' => true, 'reason' => null, 'message' => null]));
});

it('shares the read-only showcase as unable to buy credits, create projects or change its account (D-072)', function (): void {
    $this->seed(DemoAccountsSeeder::class);
    $showcase = Customer::query()->where('email', DemoAccount::Showcase->email())->sole();

    $this->actingAs($showcase, 'customer')
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.purchaseCredits.allowed', false)
            ->where('auth.can.purchaseCredits.reason', 'account_restricted')
            ->where('auth.can.createProject.allowed', false)
            ->where('auth.can.updateAccount.reason', 'demo_account_locked'));
});

it('shares a denied ability with its reason code and 2-01 copy', function (): void {
    $denied = [
        'allowed' => false,
        'reason' => 'account_restricted',
        'message' => 'Your account is restricted. Email support@example.com for help.',
    ];
    $customer = Customer::factory()->restricted()->create();
    Role::findByName('restricted', 'customer')->revokePermissionTo(['credits.purchase', 'projects.manage']);

    $this->actingAs($customer, 'customer')
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.createProject', $denied)
            ->where('auth.can.purchaseCredits', $denied));
});

it('shares no customer and no abilities with guests', function (): void {
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.customer', null)
            ->where('auth.can', null));
});
