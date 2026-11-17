<?php

declare(strict_types=1);

use App\Enums\Auth\DemoAccount;
use App\Models\Customer;
use Database\Seeders\DemoAccountsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| P02 demo panel shell (spec 3-01 issue 4): seeded logins only when
| DEMO_LOGIN_ENABLED=true. The "Try a demo account" button is spec 4.
*/

it('shows no demo panel when the flag is off', function (): void {
    config(['takes.demo.enabled' => false]);
    $this->seed(DemoAccountsSeeder::class);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login')->where('demoPanel', null));
});

it('offers only "Try a demo account" when nothing was seeded (spec 4)', function (): void {
    config(['takes.demo.enabled' => true]);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('demoPanel.tryDemo', 'Try a demo account')
            ->has('demoPanel.logins', 0));
});

it('lists the seeded logins in order when the flag is on', function (): void {
    config(['takes.demo.enabled' => true]);
    $this->seed(DemoAccountsSeeder::class);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('demoPanel.title', 'Evaluating this take-home?')
            ->has('demoPanel.logins', 4)
            ->where('demoPanel.logins.0.key', 'showcase')
            ->where('demoPanel.logins.1', [
                'key' => 'demo_customer',
                'label' => 'Demo customer · $10.00 credit',
                'email' => 'demo@example.com',
                'password' => 'password',
            ])
            ->where('demoPanel.logins.2.key', 'low_balance')
            ->where('demoPanel.logins.3.key', 'new_customer'));
});

it('lists only the accounts that exist', function (): void {
    config(['takes.demo.enabled' => true]);
    Customer::factory()->create(['email' => DemoAccount::NewCustomer->email()]);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('demoPanel.logins', 1)
            ->where('demoPanel.logins.0.key', 'new_customer'));
});

it('signs in with one click through the normal customer login', function (): void {
    config(['takes.demo.enabled' => true]);
    $this->seed(DemoAccountsSeeder::class);

    $this->post(route('login.store'), [
        'email' => DemoAccount::LowBalance->email(),
        'password' => config('takes.demo.password'),
    ])->assertRedirect(route('projects.index', absolute: false));

    $this->assertAuthenticatedAs(Customer::query()->where('email', DemoAccount::LowBalance->email())->sole(), 'customer');
});
