<?php

declare(strict_types=1);

use App\DTOs\Billing\EstimateDTO;
use App\Enums\Auth\DenialReason;
use App\Models\Customer;
use App\Models\Take;
use App\Models\User;
use App\Support\Money\Nanodollars;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['takes.daily_cap_per_customer' => 3, 'takes.daily_cap_global' => 5]);
});

/** A take whose max charge (the hold) is `$maxChargeNanos`. */
function takeEstimate(int $maxChargeNanos = 467_343_750): EstimateDTO
{
    return new EstimateDTO(
        minCost: Nanodollars::zero(),
        maxCost: Nanodollars::fromInt(intdiv($maxChargeNanos * 4, 5)),
        minCharge: Nanodollars::zero(),
        maxCharge: Nanodollars::fromInt($maxChargeNanos),
    );
}

function inspectCreateTake(Customer $customer, EstimateDTO $estimate): Response
{
    return Gate::forUser($customer)->inspect('create', [Take::class, $estimate]);
}

it('allows a customer whose available credit covers the max charge', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(1_000_000_000)->create();

    expect(inspectCreateTake($customer, takeEstimate())->allowed())->toBeTrue();
});

it('allows a max charge exactly equal to available credit', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(1_000_000_000, 532_656_250)->create();

    expect(inspectCreateTake($customer, takeEstimate(467_343_750))->allowed())->toBeTrue();
});

it('denies a restricted customer with account_restricted, before any other check', function (): void {
    $customer = Customer::factory()->restricted()->create();
    Take::factory()->count(3)->create(['customer_id' => $customer->id]);

    $response = inspectCreateTake($customer, takeEstimate());

    expect($response->denied())->toBeTrue()
        ->and($response->code())->toBe(DenialReason::AccountRestricted->value)
        ->and($response->message())->toBe('Your account is restricted. Email support@example.com for help.');
});

it('denies with daily_take_cap when the customer reached the per-customer cap today (UTC)', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(10_000_000_000)->create();
    Take::factory()->count(3)->create(['customer_id' => $customer->id]);

    expect(inspectCreateTake($customer, takeEstimate())->code())->toBe(DenialReason::DailyTakeCap->value);
});

it('counts only takes created on the current UTC day', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(10_000_000_000)->create();
    Take::factory()->count(3)->create(['customer_id' => $customer->id, 'created_at' => now()->utc()->subDay()->endOfDay()]);

    expect(inspectCreateTake($customer, takeEstimate())->allowed())->toBeTrue();
});

it('denies with demo_quota_reached when today\'s global cap is used up by anyone', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(10_000_000_000)->create();
    Take::factory()->count(5)->create();

    $response = inspectCreateTake($customer, takeEstimate());

    expect($response->code())->toBe(DenialReason::DemoQuotaReached->value)
        ->and($response->message())->toBe("Today's shared capacity is used up. It resets at 00:00 UTC.");
});

it('denies with insufficient_credits when available is below the max charge', function (): void {
    // available = 0.5 − 0.1 = 0.4 < 0.467343750
    $customer = Customer::factory()->withRole()->withCachedBalance(500_000_000, 100_000_000)->create();

    expect(inspectCreateTake($customer, takeEstimate())->code())->toBe(DenialReason::InsufficientCredits->value);
});

it('denies with insufficient_credits when available is negative', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(-5_000_000)->create();

    expect(inspectCreateTake($customer, takeEstimate(1))->code())->toBe(DenialReason::InsufficientCredits->value);
});

it('lets the owner view a take and hides other customers\' takes as 404', function (): void {
    $owner = Customer::factory()->withRole()->create();
    $take = Take::factory()->create(['customer_id' => $owner->id]);
    $other = Customer::factory()->withRole()->create();

    expect(Gate::forUser($owner)->inspect('view', $take)->allowed())->toBeTrue()
        ->and(Gate::forUser($other)->inspect('view', $take)->status())->toBe(404);
});

it('never authorizes an admin user through a customer policy', function (): void {
    $admin = User::factory()->create();

    expect(Gate::forUser($admin)->inspect('create', [Take::class, takeEstimate()])->allowed())->toBeFalse();
});
