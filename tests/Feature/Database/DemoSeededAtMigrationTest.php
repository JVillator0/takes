<?php

declare(strict_types=1);

use App\Actions\Billing\GrantWelcomeCreditAction;
use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The demo_seeded_at backfill (#167, D-072): an existing account is stamped
| only with both of the seeder's signs (its exact seeded name, no welcome
| credit), so a squatter is never stamped and the seeder refuses it later.
*/

it('stamps only the accounts the demo seeder created', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_18_100000_add_demo_seeded_at_to_customers_table.php');
    $migration->down();

    $seeded = Customer::factory()->create(['email' => 'demo@example.com', 'name' => 'Demo customer']);
    // Registered while the welcome credit was off: no ledger row, another name.
    Customer::factory()->create(['email' => 'showcase@example.com', 'name' => 'Squatter']);
    // Registered with the seeded name while the welcome credit was on.
    $creditedSquatter = Customer::factory()->create(['email' => 'new@example.com', 'name' => 'New customer']);
    config(['takes.welcome_credit_nanos' => 1_000_000_000]);
    app(GrantWelcomeCreditAction::class)->execute($creditedSquatter);
    Customer::factory()->create(['email' => 'ana@example.com', 'name' => 'Demo customer']);

    $migration->up();

    // Neither squatter, nor a non-demo address with a seeded name.
    expect(DB::table('customers')->whereNotNull('demo_seeded_at')->pluck('id')->all())->toBe([$seeded->id]);
});
