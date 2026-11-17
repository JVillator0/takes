<?php

declare(strict_types=1);

use App\Actions\Billing\GrantWelcomeCreditAction;
use App\Enums\Auth\CustomerRole;
use App\Enums\Auth\DenialReason;
use App\Enums\Billing\LedgerEntryType;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| "Try a demo account" (D-045, D-065, spec 4 "Demo account"): a fresh
| ephemeral customer with the welcome credit, signed in and sent to a new
| project; throttled per IP with `rate_limited`; logged out once expired.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['takes.demo.enabled' => true, 'takes.demo.throttle_per_ip_per_hour' => 2]);
});

it('creates a fresh demo customer with the welcome credit and signs it in', function (): void {
    $this->freezeSecond();

    $response = $this->post(route('demo.store'));

    $customer = Customer::query()->sole();
    $project = Project::query()->sole();
    $grant = LedgerEntry::query()->sole();

    $response->assertRedirect(route('projects.show', $project));
    $this->assertAuthenticatedAs($customer, 'customer');
    expect($customer->is_demo)->toBeTrue()
        ->and($customer->demo_expires_at?->equalTo(now()->addHours(24)))->toBeTrue()
        ->and($customer->hasRole(CustomerRole::Customer->value))->toBeTrue()
        ->and($customer->balance_nanos)->toBe(config('takes.welcome_credit_nanos'))
        ->and($grant->type)->toBe(LedgerEntryType::PromoGrant)
        ->and($grant->reason)->toBe(GrantWelcomeCreditAction::REASON)
        ->and($project->customer_id)->toBe($customer->id)
        ->and($project->name)->toBe(__('takes.projects.untitled'));
});

it('never shares an account: every click is a new customer', function (): void {
    $this->post(route('demo.store'));
    $this->post('/logout');
    $this->post(route('demo.store'));

    expect(Customer::query()->count())->toBe(2)
        ->and(Customer::query()->distinct()->count('email'))->toBe(2);
});

it('throttles per IP with 429 rate_limited for JSON callers', function (): void {
    foreach (range(1, 2) as $attempt) {
        $this->postJson(route('demo.store'))->assertRedirect();
        auth('customer')->logout();
    }

    $this->postJson(route('demo.store'))
        ->assertStatus(429)
        ->assertExactJson(['code' => DenialReason::RateLimited->value, 'message' => DenialReason::RateLimited->message()]);

    expect(Customer::query()->count())->toBe(2);
});

it('sends an Inertia visit back with the rate_limited denial', function (): void {
    foreach (range(1, 2) as $attempt) {
        $this->post(route('demo.store'));
        auth('customer')->logout();
    }

    $this->from(route('home'))
        ->post(route('demo.store'), headers: ['X-Inertia' => 'true'])
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors(['denial' => DenialReason::RateLimited->message()]);
});

it('logs the demo customer out once it expires', function (): void {
    $this->post(route('demo.store'));

    $this->travel(25)->hours();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest('customer');
});

it('is off unless DEMO_LOGIN_ENABLED is set', function (): void {
    config(['takes.demo.enabled' => false]);

    $this->post(route('demo.store'))->assertNotFound();

    expect(Customer::query()->count())->toBe(0);
});
