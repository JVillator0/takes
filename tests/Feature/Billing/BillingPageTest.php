<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Queries\Billing\ListBillingActivityQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Billing;
use Tests\Support\BillingDemo;

/*
 * GET /billing (spec 6 issue 2, P08): cards, spend by feature and Activity.
 * Amounts are the demo dataset's (D-037), exact on this money page (D-042).
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('serves the billing page to a customer on the customer guard (D-032)', function (): void {
    $customer = Customer::factory()->withRole()->create();

    $this->actingAs($customer, 'customer')
        ->get(route('billing.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/Show')
            ->where('auth.customer.id', $customer->id)
            ->where('activity.rows', [])
            ->where('invoices', []));
});

it('sends guests to the login page and never serves an admin session', function (): void {
    $this->get(route('billing.show'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('billing.show'))
        ->assertRedirect(route('login'));
});

it('shows the demo dataset cards exactly (D-037, D-042)', function (): void {
    ['customer' => $ana] = BillingDemo::ana();

    $this->actingAs($ana, 'customer')
        ->get(route('billing.show'))
        ->assertInertia(fn (Assert $page) => $page
            // Exact on the page: the pill's $10.3336 rounding never shows here.
            ->where('summary.available', '$10.33358125')
            ->where('summary.balance', '$10.64754375')
            ->where('summary.onHold', '$0.3139625')
            ->where('summary.spent.month', 'November 2026')
            ->where('summary.spent.images', '$0.35214375')
            ->where('summary.spent.aiSuggestions', '$0.0003125')
            ->where('summary.spent.total', '$0.35245625')
            ->where('packs', [
                ['usd' => 5, 'amount' => '$5.00', 'tax' => '$0.00'],
                ['usd' => 10, 'amount' => '$10.00', 'tax' => '$0.00'],
                ['usd' => 25, 'amount' => '$25.00', 'tax' => '$0.00'],
            ])
            ->where('auth.customer.available', '$10.3336'));
});

it('lists one line per usage and the non-charge ledger entries, newest first, with the running balance', function (): void {
    ['customer' => $ana, 'takes' => $takes] = BillingDemo::ana();

    $response = $this->actingAs($ana, 'customer')->get(route('billing.show'));

    $rows = collect($response->inertiaProps('activity.rows'))
        ->map(fn (array $row): array => [$row['occurredAtLabel'], $row['kind'], $row['status'], $row['amount'], $row['maxCharge'], $row['balance']])
        ->all();

    expect($rows)->toBe([
        ['Nov 17 12:02', 'image', 'on_hold', null, '$0.11384375', '$10.64754375'],
        ['Nov 17 12:01', 'image', 'on_hold', null, '$0.113825', '$10.64754375'],
        ['Nov 17 12:01', 'image', 'on_hold', null, '$0.08629375', '$10.64754375'],
        ['Nov 17 12:01', 'image', 'charged', '-$0.0740375', '$0.46734375', '$10.64754375'],
        ['Nov 17 11:58', 'ai_suggestion', 'charged', '-$0.0003125', '$0.0003125', '$10.72158125'],
        ['Nov 17 11:40', 'image', 'not_charged', '$0.00', '$0.46734375', '$10.72189375'],
        ['Nov 17 10:02', 'purchase', null, '+$10.00', null, '$10.72189375'],
        ['Nov 16 18:52', 'image', 'charged', '-$0.107725', '$0.46734375', '$0.72189375'],
        ['Nov 16 18:40', 'image', 'charged', '-$0.0691', '$0.46734375', '$0.82961875'],
        ['Nov 16 18:31', 'image', 'charged', '-$0.10128125', '$0.46734375', '$0.89871875'],
        ['Nov 16 18:20', 'welcome_credit', null, '+$1.00', null, '$1.00'],
    ])
        ->and($response->inertiaProps('activity.hasMore'))->toBeFalse();

    $purchase = collect($response->inertiaProps('activity.rows'))->firstWhere('kind', 'purchase');
    $invoice = Invoice::query()->sole();

    expect($purchase['invoice'])->toBe(['number' => 'INV-2026-000004', 'href' => route('billing.invoices.show', $invoice)]);

    $ai = collect($response->inertiaProps('activity.rows'))->firstWhere('kind', 'ai_suggestion');

    expect($ai['detail'])->toBe('Make it portrait with warmer light');
});

it('pages Activity without losing or repeating a line', function (): void {
    ['customer' => $ana] = BillingDemo::ana();
    $query = app(ListBillingActivityQuery::class);

    $first = $query->execute($ana, 1, 4);
    $second = $query->execute($ana, 2, 4);
    $third = $query->execute($ana, 3, 4);
    $keys = array_map(fn ($row): string => $row->key, [...$first->rows, ...$second->rows, ...$third->rows]);

    expect([$first->hasMore, $second->hasMore, $third->hasMore])->toBe([true, true, false])
        ->and($keys)->toHaveCount(11)
        ->and(array_unique($keys))->toHaveCount(11);
});

it('counts a take settled after midnight UTC in the month of its settlement', function (): void {
    $customer = Billing::fundedCustomer(10_000_000_000);
    $customer->assignRole('customer');

    // Opened on Oct 31 at 23:59 UTC, settled on Nov 1 at 00:01 UTC.
    CarbonImmutable::setTestNow('2026-10-31T23:59:00Z');
    $usage = Billing::openForNewTake($customer);
    CarbonImmutable::setTestNow('2026-11-01T00:01:00Z');
    BillingDemo::settle($usage, [115, 1_530, 6_000, 1_000]);

    CarbonImmutable::setTestNow('2026-11-15T12:00:00Z');
    $this->actingAs($customer, 'customer')
        ->get(route('billing.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.spent.month', 'November 2026')
            ->where('summary.spent.images', '$0.33546875')
            ->where('summary.spent.aiSuggestions', '$0.00'));

    CarbonImmutable::setTestNow('2026-10-31T23:59:59Z');
    $this->actingAs($customer, 'customer')
        ->get(route('billing.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.spent.month', 'October 2026')
            ->where('summary.spent.images', '$0.00'));
});

it('renders exact amounts whose 4-decimal rounding would differ', function (): void {
    // 74,037,500 nanodollars: $0.0740 on a card, $0.0740375 here.
    ['customer' => $ana] = BillingDemo::ana();

    $row = collect($this->actingAs($ana, 'customer')->get(route('billing.show'))->inertiaProps('activity.rows'))
        ->first(fn (array $row): bool => $row['amount'] === '-$0.0740375');

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('charged');
});

it('only shows the signed-in customer\'s own money', function (): void {
    ['customer' => $ana] = BillingDemo::ana();
    $other = Customer::factory()->withRole()->create();

    $this->actingAs($other, 'customer')
        ->get(route('billing.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.available', '$0.00')
            ->where('summary.spent.total', '$0.00')
            ->where('activity.rows', [])
            ->where('invoices', []));

    expect($ana->invoices()->count())->toBe(1);
});

it('lists the customer\'s invoices with exact totals', function (): void {
    ['customer' => $ana] = BillingDemo::ana();
    $invoice = Invoice::query()->sole();

    $this->actingAs($ana, 'customer')
        ->get(route('billing.show'))
        ->assertInertia(fn (Assert $page) => $page->where('invoices', [[
            'id' => $invoice->id,
            'number' => 'INV-2026-000004',
            'status' => 'paid',
            'issuedAt' => '2026-11-17T10:02:00Z',
            'issuedAtLabel' => 'Nov 17, 2026',
            'total' => '$10.00',
            'href' => route('billing.invoices.show', $invoice),
        ]]));
});

it('rejects an Activity page that is not a positive integer', function (): void {
    $customer = Customer::factory()->withRole()->create();

    $this->actingAs($customer, 'customer')
        ->get(route('billing.show', ['page' => 'x']))
        ->assertSessionHasErrors('page');
});
