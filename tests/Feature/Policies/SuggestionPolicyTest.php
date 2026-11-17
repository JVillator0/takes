<?php

declare(strict_types=1);

use App\DTOs\Billing\EstimateDTO;
use App\Enums\Auth\DenialReason;
use App\Enums\Billing\Provider;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config([
        'takes.ai.enabled' => true,
        'takes.ai.daily_budget_nanos' => 1_000_000,
        'takes.ai.daily_limit_per_customer' => 2,
    ]);
});

function inspectSuggest(Customer $customer, ?EstimateDTO $estimate = null): Response
{
    return Gate::forUser($customer)->inspect('create', [Suggestion::class, $estimate]);
}

function aiEstimate(int $maxChargeNanos): EstimateDTO
{
    return new EstimateDTO(
        minCost: Nanodollars::zero(),
        maxCost: Nanodollars::fromInt(0),
        minCharge: Nanodollars::zero(),
        maxCharge: Nanodollars::fromInt($maxChargeNanos),
    );
}

it('allows a customer with credit', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(1_000_000_000)->create();

    expect(inspectSuggest($customer)->allowed())->toBeTrue()
        ->and(inspectSuggest($customer, aiEstimate(500_000))->allowed())->toBeTrue();
});

it('denies a restricted customer with account_restricted', function (): void {
    $customer = Customer::factory()->restricted()->withCachedBalance(1_000_000_000)->create();

    expect(inspectSuggest($customer)->code())->toBe(DenialReason::AccountRestricted->value);
});

it('denies with ai_paused when suggestions are switched off', function (): void {
    config(['takes.ai.enabled' => false]);
    $customer = Customer::factory()->withRole()->withCachedBalance(1_000_000_000)->create();

    expect(inspectSuggest($customer)->code())->toBe(DenialReason::AiPaused->value);
});

it('denies with ai_paused when today\'s global AI budget is spent (settled cost + open max cost)', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(1_000_000_000)->create();

    UsageRecord::factory()->forSuggestion()->settled(600_000)->create();
    expect(inspectSuggest($customer)->allowed())->toBeTrue();

    // An open call counts at its max cost (400_000 in the factory): 1_000_000 ≥ budget.
    UsageRecord::factory()->forSuggestion()->create();
    expect(inspectSuggest($customer)->code())->toBe(DenialReason::AiPaused->value);
});

it('counts an open AI call opened before midnight: it is still held today', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(1_000_000_000)->create();
    config(['takes.ai.daily_budget_nanos' => 400_000]);

    UsageRecord::factory()->forSuggestion()->create(['created_at' => now()->utc()->subDay()->endOfDay()]);

    expect(inspectSuggest($customer)->code())->toBe(DenialReason::AiPaused->value);
});

it('ignores Pixelforge spend and yesterday\'s AI spend for the AI budget', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(1_000_000_000)->create();

    UsageRecord::factory()->settled(5_000_000)->create(['provider' => Provider::Pixelforge]);
    UsageRecord::factory()->forSuggestion()->settled(5_000_000)->create(['billing_date' => now()->utc()->subDay()->toDateString()]);
    UsageRecord::factory()->forSuggestion()->voided()->create();

    expect(inspectSuggest($customer)->allowed())->toBeTrue();
});

it('denies with ai_daily_limit when the customer reached today\'s limit', function (): void {
    $customer = Customer::factory()->withRole()->withCachedBalance(1_000_000_000)->create();
    Suggestion::factory()->count(2)->create(['customer_id' => $customer->id]);

    expect(inspectSuggest($customer)->code())->toBe(DenialReason::AiDailyLimit->value);
});

it('denies with insufficient_credits when available cannot cover the call', function (): void {
    $empty = Customer::factory()->withRole()->create();
    $low = Customer::factory()->withRole()->withCachedBalance(100_000)->create();

    expect(inspectSuggest($empty)->code())->toBe(DenialReason::InsufficientCredits->value)
        ->and(inspectSuggest($low, aiEstimate(500_000))->code())->toBe(DenialReason::InsufficientCredits->value);
});

it('hides another customer\'s suggestion as 404', function (): void {
    $suggestion = Suggestion::factory()->create();
    $other = Customer::factory()->withRole()->create();

    expect(Gate::forUser($suggestion->customer)->inspect('view', $suggestion)->allowed())->toBeTrue()
        ->and(Gate::forUser($other)->inspect('view', $suggestion)->status())->toBe(404);
});
