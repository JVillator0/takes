<?php

declare(strict_types=1);

use App\Queries\Billing\ListLedgerInvariantViolationsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Billing;

/*
 * The bounded variant for page loads (spec 7 ledger-drift widget, issue #102):
 * SQL checks still cover every row; only settled usages billed on or after
 * the window start are re-priced in PHP.
 */

it('re-prices only the settled usages inside the window when bounded', function (): void {
    $customer = Billing::fundedCustomer();
    // Billing::settle() bills on 2026-11-17.
    $old = Billing::settle(Billing::openForNewTake($customer));
    $recent = Billing::settle(Billing::openForNewTake($customer));
    DB::table('usage_records')->where('id', $old->id)->update(['billing_date' => '2026-10-01']);
    DB::table('usage_records')->whereIn('id', [$old->id, $recent->id])->update(['provider_cost_nanos' => Billing::EXAMPLE_COST - 1]);

    $bounded = implode("\n", app(ListLedgerInvariantViolationsQuery::class)->execute(CarbonImmutable::parse('2026-11-10', 'UTC')));
    $full = implode("\n", app(ListLedgerInvariantViolationsQuery::class)->execute());

    expect($bounded)->toContain("usage {$recent->id}: provider_cost_nanos")
        ->not->toContain("usage {$old->id}: provider_cost_nanos")
        ->and($full)->toContain("usage {$recent->id}: provider_cost_nanos")
        ->toContain("usage {$old->id}: provider_cost_nanos");
});

it('keeps the SQL checks over every row when bounded', function (): void {
    $customer = Billing::fundedCustomer();
    DB::table('customers')->where('id', $customer->id)->increment('balance_nanos');

    expect(app(ListLedgerInvariantViolationsQuery::class)->execute(CarbonImmutable::parse('2030-01-01', 'UTC')))
        ->toContain("customer {$customer->id}: balance_nanos {$customer->refresh()->balance_nanos} but Σ ledger is ".($customer->balance_nanos - 1));
});
