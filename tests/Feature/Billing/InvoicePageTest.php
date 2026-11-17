<?php

declare(strict_types=1);

use App\Actions\Billing\PurchaseCreditsAction;
use App\Enums\Auth\CustomerRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * GET /billing/invoices/{invoice} (spec 6 issue 3, P09): the printable
 * invoice, owner only. Another customer's invoice is a 404, like an unknown
 * one, so an invoice's existence never leaks.
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->customer = Customer::factory()->withRole()->create(['name' => 'Ana López', 'email' => 'ana@example.com']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('shows the owner the printable invoice with exact amounts', function (): void {
    CarbonImmutable::setTestNow('2026-11-17T10:02:00Z');
    $purchase = app(PurchaseCreditsAction::class)->execute($this->customer, 10, (string) Str::uuid());
    $invoice = Invoice::query()->sole();

    $this->actingAs($this->customer, 'customer')
        ->get(route('billing.invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/Invoice')
            ->where('invoice', [
                'id' => $invoice->id,
                'number' => 'INV-2026-000001',
                'status' => 'paid',
                'issuedAt' => '2026-11-17T10:02:00Z',
                'issuedAtLabel' => 'Nov 17, 2026',
                'total' => '$10.00',
                'href' => route('billing.invoices.show', $invoice),
                'currency' => 'USD',
                'billedName' => 'Ana López',
                'billedEmail' => 'ana@example.com',
                'reference' => $purchase->reference,
                'quantity' => 1,
                'unitPrice' => '$10.00',
                'lineAmount' => '$10.00',
                'tax' => '$0.00',
            ]));
});

it('renders an exact total whose 4-decimal rounding would differ', function (): void {
    // A display fixture: packs are whole dollars, but the view must never round.
    $purchase = Purchase::factory()->create(['customer_id' => $this->customer->id, 'amount_nanos' => 74_037_500]);
    $invoice = Invoice::factory()->create(['purchase_id' => $purchase->id]);

    $this->actingAs($this->customer, 'customer')
        ->get(route('billing.invoices.show', $invoice))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.total', '$0.0740375')
            ->where('invoice.unitPrice', '$0.0740375')
            ->where('invoice.lineAmount', '$0.0740375'));
});

it('answers 404 for another customer\'s invoice, the same as an unknown one', function (): void {
    $invoice = Invoice::factory()->create();

    $this->actingAs($this->customer, 'customer')
        ->get(route('billing.invoices.show', $invoice))
        ->assertNotFound();

    $this->actingAs($this->customer, 'customer')
        ->get(route('billing.invoices.show', (string) Str::ulid()))
        ->assertNotFound();
});

it('keeps the invoice visible to the read-only showcase role (ownership only)', function (): void {
    $showcase = Customer::factory()->withRole(CustomerRole::Showcase)->create();
    $purchase = Purchase::factory()->create(['customer_id' => $showcase->id]);
    $invoice = Invoice::factory()->create(['purchase_id' => $purchase->id]);

    $this->actingAs($showcase, 'customer')
        ->get(route('billing.invoices.show', $invoice))
        ->assertOk();
});

it('requires a customer session; an admin session is not one', function (): void {
    $invoice = Invoice::factory()->create();

    $this->get(route('billing.invoices.show', $invoice))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('billing.invoices.show', $invoice))
        ->assertRedirect(route('login'));
});

it('links the invoice from the purchase toast', function (): void {
    $this->actingAs($this->customer, 'customer')
        ->from(route('billing.show'))
        ->post(route('billing.purchases.store'), ['package' => 5, 'idempotency_key' => (string) Str::uuid()])
        ->assertRedirect(route('billing.show'))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Credits added',
            'action' => [
                'label' => 'View invoice',
                'href' => route('billing.invoices.show', Invoice::query()->sole()),
            ],
        ]);
});
