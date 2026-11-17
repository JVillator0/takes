<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Take;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\Billing;

/*
 * Spec 6 Tests, "Blocking": available < max ⇒ cannot generate; after a
 * purchase ⇒ can. The policy reads `available = balance − held` (D-024), so
 * a purchase lifts the block with no permission change (D-015).
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('blocks a take whose max is above available and allows it after a purchase', function (): void {
    // The low-balance demo account: $0.01 against a $0.46734375 hold.
    $customer = Customer::factory()->withRole()->create();
    Billing::fund($customer, 10_000_000);
    $estimate = Billing::estimate();

    $before = Gate::forUser($customer->refresh())->inspect('create', [Take::class, $estimate]);

    expect($before->denied())->toBeTrue()
        ->and($before->code())->toBe('insufficient_credits');

    $this->actingAs($customer, 'customer')
        ->post(route('billing.purchases.store'), ['package' => 5, 'idempotency_key' => (string) Str::uuid()])
        ->assertRedirect();

    $after = Gate::forUser($customer->refresh())->inspect('create', [Take::class, $estimate]);

    expect($after->allowed())->toBeTrue()
        ->and($customer->available()->formatExact())->toBe('$5.01');
});
