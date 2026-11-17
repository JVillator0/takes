<?php

declare(strict_types=1);

use App\Actions\Billing\IssueInvoiceAction;
use App\Actions\Billing\PurchaseCreditsAction;
use App\Enums\Billing\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Billing;

/*
 * Invoices per purchase (spec 6 issue 3): IssueInvoiceAction, its call from
 * PurchaseCreditsAction, gapless yearly numbering and the table constraints.
 * The parallel cases live in tests/Committed/InvoiceConcurrencyTest.php and
 * the outside-a-transaction guard in tests/Committed/TransactionGuardTest.php.
 */

function buyCredits(Customer $customer, int $usd = 10, ?string $key = null): Purchase
{
    return app(PurchaseCreditsAction::class)->execute($customer, $usd, $key ?? (string) Str::uuid());
}

beforeEach(function (): void {
    $this->customer = Customer::factory()->create(['name' => 'Ana López', 'email' => 'ana@example.com']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('creates exactly one purchase, one ledger entry and one invoice with the next number', function (): void {
    CarbonImmutable::setTestNow('2026-11-17T10:02:00Z');
    DB::table('invoice_counters')->insert(['year' => 2026, 'last_number' => 3]);

    $purchase = buyCredits($this->customer, 10);

    $invoice = Invoice::query()->sole();

    expect(Purchase::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and($invoice->number)->toBe('INV-2026-000004')
        ->and($invoice->purchase_id)->toBe($purchase->id)
        ->and($invoice->customer_id)->toBe($this->customer->id)
        ->and($invoice->total_nanos)->toBe(10_000_000_000)
        ->and($invoice->currency)->toBe('USD')
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->issued_at->toIso8601ZuluString())->toBe('2026-11-17T10:02:00Z')
        ->and($invoice->billed_name)->toBe('Ana López')
        ->and($invoice->billed_email)->toBe('ana@example.com')
        ->and($purchase->invoice?->is($invoice))->toBeTrue();

    Billing::assertCachedColumnsMatch($this->customer);
});

it('creates nothing new when the purchase is replayed with the same key', function (): void {
    $key = (string) Str::uuid();

    $first = buyCredits($this->customer, 5, $key);
    $replay = buyCredits($this->customer, 5, $key);

    expect($replay->id)->toBe($first->id)
        ->and($replay->invoice?->number)->toBe($first->invoice?->number)
        ->and(Purchase::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(DB::table('invoice_counters')->value('last_number'))->toBe(1);
});

it('issues one invoice per purchase however often it is asked', function (): void {
    $purchase = buyCredits($this->customer);

    $again = DB::transaction(fn (): Invoice => app(IssueInvoiceAction::class)->execute($purchase));

    expect($again->is($purchase->invoice))->toBeTrue()
        ->and(Invoice::query()->count())->toBe(1)
        ->and(DB::table('invoice_counters')->value('last_number'))->toBe(1);
});

it('numbers consecutively across customers and restarts every UTC year', function (): void {
    $other = Customer::factory()->create();

    CarbonImmutable::setTestNow('2026-12-31T23:59:59Z');
    buyCredits($this->customer);
    buyCredits($other);

    // 00:00:01 UTC on Jan 1 is a new year even where it is still Dec 31.
    CarbonImmutable::setTestNow('2027-01-01T00:00:01Z');
    buyCredits($this->customer);
    buyCredits($other);

    expect(Invoice::query()->orderBy('issued_at')->orderBy('number')->pluck('number')->all())->toBe([
        'INV-2026-000001',
        'INV-2026-000002',
        'INV-2027-000001',
        'INV-2027-000002',
    ]);
});

it('leaves no gap when the purchase transaction rolls back', function (): void {
    buyCredits($this->customer);

    try {
        DB::transaction(function (): void {
            buyCredits($this->customer);

            throw new RuntimeException('payment step failed after the invoice');
        });
    } catch (RuntimeException) {
        // The purchase, ledger entry, invoice and counter increment roll back together.
    }

    buyCredits($this->customer);

    expect(Invoice::query()->orderBy('number')->pluck('number')->all())->toBe([
        sprintf('INV-%d-000001', now()->utc()->year),
        sprintf('INV-%d-000002', now()->utc()->year),
    ])->and(Purchase::query()->count())->toBe(2);
});

it('refuses to invoice a purchase that is not paid', function (): void {
    $purchase = Purchase::factory()->pending()->create(['customer_id' => $this->customer->id]);

    DB::transaction(fn () => app(IssueInvoiceAction::class)->execute($purchase));
})->throws(InvalidArgumentException::class);

describe('constraints', function (): void {
    it('allows one invoice per purchase', function (): void {
        $purchase = Purchase::factory()->create(['customer_id' => $this->customer->id]);
        Invoice::factory()->create(['purchase_id' => $purchase->id]);

        expect(fn () => Invoice::factory()->create(['purchase_id' => $purchase->id]))
            ->toThrow(QueryException::class, 'invoices_purchase_id_unique');
    });

    it('rejects an invoice for another customer\'s purchase', function (): void {
        $purchase = Purchase::factory()->create();

        expect(fn () => Invoice::factory()->create(['purchase_id' => $purchase->id, 'customer_id' => $this->customer->id]))
            ->toThrow(QueryException::class, 'invoices_purchase_id_customer_id_foreign');
    });

    it('rejects a malformed number, a non-positive total or another currency', function (array $overrides, string $constraint): void {
        expect(fn () => Invoice::factory()->create($overrides))->toThrow(QueryException::class, $constraint);
    })->with([
        'number' => [['number' => 'INV-26-1'], 'invoices_number_format_check'],
        'total' => [['total_nanos' => 0], 'invoices_total_nanos_check'],
        'currency' => [['currency' => 'EUR'], 'invoices_currency_check'],
    ]);
});
