<?php

declare(strict_types=1);

use App\Actions\Billing\PurchaseCreditsAction;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\PurchaseStatus;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\Billing;

/*
 * PurchaseCreditsAction and POST /billing/purchases (spec 3-03 issue 6).
 * Until spec 3-01 lands, these tests declare the `customer` guard and the
 * `credits.purchase` ability themselves.
 */

beforeEach(function (): void {
    config([
        'auth.guards.customer' => ['driver' => 'session', 'provider' => 'customers'],
        'auth.providers.customers' => ['driver' => 'eloquent', 'model' => Customer::class],
    ]);
    Gate::define('credits.purchase', fn (Customer $customer): bool => true);

    $this->customer = Customer::factory()->create();
});

describe('action', function (): void {
    it('creates a paid purchase and one purchase ledger entry, raising the balance by the pack', function (int $usd): void {
        $purchase = app(PurchaseCreditsAction::class)->execute($this->customer, $usd, (string) Str::uuid());

        expect($purchase->status)->toBe(PurchaseStatus::Paid)
            ->and($purchase->paid_at)->not->toBeNull()
            ->and($purchase->amount_nanos)->toBe($usd * 1_000_000_000)
            ->and($purchase->reference)->toStartWith('PUR-')
            ->and($this->customer->refresh()->balance_nanos)->toBe($usd * 1_000_000_000);

        $entry = LedgerEntry::query()->where('purchase_id', $purchase->id)->sole();

        expect($entry->type)->toBe(LedgerEntryType::Purchase)
            ->and($entry->amount_nanos)->toBe($usd * 1_000_000_000);

        Billing::assertCachedColumnsMatch($this->customer);
    })->with([5, 10, 25]);

    it('creates nothing when replayed with the same idempotency key', function (): void {
        $key = (string) Str::uuid();

        $first = app(PurchaseCreditsAction::class)->execute($this->customer, 10, $key);
        $replay = app(PurchaseCreditsAction::class)->execute($this->customer, 10, $key);

        expect($replay->id)->toBe($first->id)
            ->and(Purchase::query()->count())->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and($this->customer->refresh()->balance_nanos)->toBe(10_000_000_000);
    });

    it('scopes the idempotency key to the customer', function (): void {
        $key = (string) Str::uuid();
        $other = Customer::factory()->create();

        app(PurchaseCreditsAction::class)->execute($this->customer, 5, $key);
        app(PurchaseCreditsAction::class)->execute($other, 5, $key);

        expect(Purchase::query()->count())->toBe(2);
    });

    it('rejects an amount that is not a pack', function (): void {
        app(PurchaseCreditsAction::class)->execute($this->customer, 7, (string) Str::uuid());
    })->throws(InvalidArgumentException::class);

    it('is backed by the unique (customer_id, idempotency_key) index', function (): void {
        $key = (string) Str::uuid();
        Purchase::factory()->create(['customer_id' => $this->customer->id, 'idempotency_key' => $key]);

        Purchase::factory()->create(['customer_id' => $this->customer->id, 'idempotency_key' => $key]);
    })->throws(UniqueConstraintViolationException::class);
});

describe('POST /billing/purchases', function (): void {
    it('buys a pack as the logged-in customer', function (): void {
        $this->actingAs($this->customer, 'customer')
            ->from('/takes')
            ->post(route('billing.purchases.store'), ['package' => 25, 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect('/takes');

        expect($this->customer->refresh()->balance_nanos)->toBe(25_000_000_000);
    });

    it('creates nothing on a replayed request', function (): void {
        $payload = ['package' => 5, 'idempotency_key' => (string) Str::uuid()];

        $this->actingAs($this->customer, 'customer')->post(route('billing.purchases.store'), $payload)->assertRedirect();
        $this->actingAs($this->customer, 'customer')->post(route('billing.purchases.store'), $payload)->assertRedirect();

        expect(Purchase::query()->count())->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and($this->customer->refresh()->balance_nanos)->toBe(5_000_000_000);
    });

    it('validates the pack and the idempotency key', function (array $payload, string $field): void {
        $this->actingAs($this->customer, 'customer')
            ->post(route('billing.purchases.store'), $payload)
            ->assertSessionHasErrors($field);

        expect(Purchase::query()->count())->toBe(0);
    })->with([
        'not a pack' => [['package' => 7, 'idempotency_key' => '9b2f1c7e-8d1a-4c4e-9a52-3f6f7f0e1d2c'], 'package'],
        'decimal pack' => [['package' => '5.5', 'idempotency_key' => '9b2f1c7e-8d1a-4c4e-9a52-3f6f7f0e1d2c'], 'package'],
        'missing key' => [['package' => 5], 'idempotency_key'],
        'key not a uuid' => [['package' => 5, 'idempotency_key' => 'abc'], 'idempotency_key'],
    ]);

    it('is forbidden without the credits.purchase permission', function (): void {
        Gate::define('credits.purchase', fn (Customer $customer): bool => false);

        $this->actingAs($this->customer, 'customer')
            ->post(route('billing.purchases.store'), ['package' => 5, 'idempotency_key' => (string) Str::uuid()])
            ->assertForbidden();

        expect(Purchase::query()->count())->toBe(0);
    });

    it('requires a customer session', function (): void {
        $this->post(route('billing.purchases.store'), ['package' => 5, 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect();

        expect(Purchase::query()->count())->toBe(0);
    });

    it('is throttled to 5 requests per minute', function (): void {
        foreach (range(1, 5) as $attempt) {
            $this->actingAs($this->customer, 'customer')
                ->post(route('billing.purchases.store'), ['package' => 5, 'idempotency_key' => (string) Str::uuid()])
                ->assertRedirect();
        }

        $this->actingAs($this->customer, 'customer')
            ->post(route('billing.purchases.store'), ['package' => 5, 'idempotency_key' => (string) Str::uuid()])
            ->assertTooManyRequests();

        expect(Purchase::query()->count())->toBe(5)
            ->and($this->customer->refresh()->balance_nanos)->toBe(25_000_000_000);
    });
});
