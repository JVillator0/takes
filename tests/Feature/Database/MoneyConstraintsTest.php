<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\Take;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Constraint;

/**
 * Raw writes against the money tables (spec 3-03 §5): the database refuses
 * double charges, double welcome credits and amounts with the wrong sign.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ledgerRow(Customer $customer, array $overrides = []): array
{
    return [
        'customer_id' => $customer->id,
        'type' => 'adjustment',
        'amount_nanos' => 1_000,
        'created_at' => now(),
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function usageRow(Customer $customer, array $overrides = []): array
{
    return [
        'id' => (string) Str::ulid(),
        'customer_id' => $customer->id,
        'provider' => 'pixelforge',
        'billable_type' => 'take',
        'billable_id' => Take::factory()->create(['customer_id' => $customer->id])->id,
        'status' => 'open',
        'min_cost_nanos' => 100,
        'max_cost_nanos' => 200,
        'min_charge_nanos' => 125,
        'max_charge_nanos' => 250,
        'rates' => '{}',
        'markup_bps' => 2500,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

it('rejects ledger amounts with the wrong sign', function (string $type, int $amount): void {
    $usage = UsageRecord::factory()->settled()->create(['customer_id' => $this->customer->id]);
    $purchase = Purchase::factory()->create(['customer_id' => $this->customer->id]);

    Constraint::assertRejects(
        fn () => DB::table('ledger_entries')->insert(ledgerRow($this->customer, [
            'type' => $type,
            'amount_nanos' => $amount,
            'usage_record_id' => $type === 'charge' ? $usage->id : null,
            'purchase_id' => $type === 'purchase' ? $purchase->id : null,
        ])),
        Constraint::CHECK_VIOLATION,
        'ledger_entries_sign_check',
    );
})->with([
    'negative purchase' => ['purchase', -5],
    'zero purchase' => ['purchase', 0],
    'negative promo grant' => ['promo_grant', -1],
    'positive charge' => ['charge', 375],
    'zero charge' => ['charge', 0],
    'zero adjustment' => ['adjustment', 0],
]);

it('accepts adjustments in both directions', function (): void {
    DB::table('ledger_entries')->insert(ledgerRow($this->customer, ['amount_nanos' => 500]));
    DB::table('ledger_entries')->insert(ledgerRow($this->customer, ['amount_nanos' => -500]));

    expect(LedgerEntry::query()->count())->toBe(2);
});

it('rejects an unknown ledger type and entries missing their source', function (): void {
    // Postgres reports the first failing CHECK by name (the sign check also
    // rejects an unknown type); the type list itself is covered by EnumCheckParityTest.
    Constraint::assertRejects(
        fn () => DB::table('ledger_entries')->insert(ledgerRow($this->customer, ['type' => 'hold'])),
        Constraint::CHECK_VIOLATION,
    );

    Constraint::assertRejects(
        fn () => DB::table('ledger_entries')->insert(ledgerRow($this->customer, ['type' => 'charge', 'amount_nanos' => -1])),
        Constraint::CHECK_VIOLATION,
        'ledger_entries_charge_has_usage_check',
    );

    Constraint::assertRejects(
        fn () => DB::table('ledger_entries')->insert(ledgerRow($this->customer, ['type' => 'purchase', 'amount_nanos' => 1])),
        Constraint::CHECK_VIOLATION,
        'ledger_entries_purchase_has_purchase_check',
    );
});

it('charges a usage record only once', function (): void {
    $usage = UsageRecord::factory()->settled()->create(['customer_id' => $this->customer->id]);
    $charge = ledgerRow($this->customer, ['type' => 'charge', 'amount_nanos' => -375, 'usage_record_id' => $usage->id]);

    DB::table('ledger_entries')->insert($charge);

    Constraint::assertRejects(
        fn () => DB::table('ledger_entries')->insert($charge),
        Constraint::UNIQUE_VIOLATION,
        'ledger_entries_charge_usage_record_id_unique',
    );
});

it('credits a purchase only once', function (): void {
    $purchase = Purchase::factory()->create(['customer_id' => $this->customer->id]);
    $credit = ledgerRow($this->customer, ['type' => 'purchase', 'amount_nanos' => 5_000_000_000, 'purchase_id' => $purchase->id]);

    DB::table('ledger_entries')->insert($credit);

    Constraint::assertRejects(
        fn () => DB::table('ledger_entries')->insert($credit),
        Constraint::UNIQUE_VIOLATION,
        'ledger_entries_purchase_purchase_id_unique',
    );
});

it('grants the welcome credit only once per customer', function (): void {
    $welcome = ledgerRow($this->customer, ['type' => 'promo_grant', 'amount_nanos' => 1_000_000_000, 'reason' => 'welcome']);

    DB::table('ledger_entries')->insert($welcome);

    Constraint::assertRejects(
        fn () => DB::table('ledger_entries')->insert($welcome),
        Constraint::UNIQUE_VIOLATION,
        'ledger_entries_welcome_grant_unique',
    );

    // Other promo grants and other customers' welcome credits are fine.
    DB::table('ledger_entries')->insert(ledgerRow($this->customer, ['type' => 'promo_grant', 'amount_nanos' => 1, 'reason' => 'campaign']));
    DB::table('ledger_entries')->insert(ledgerRow(Customer::factory()->create(), ['type' => 'promo_grant', 'amount_nanos' => 1_000_000_000, 'reason' => 'welcome']));
});

it('refuses query-builder updates and deletes on the ledger (trigger)', function (): void {
    DB::table('ledger_entries')->insert(ledgerRow($this->customer));

    foreach ([
        fn () => DB::table('ledger_entries')->update(['amount_nanos' => 2_000]),
        fn () => LedgerEntry::query()->increment('amount_nanos'),
        fn () => DB::table('ledger_entries')->delete(),
        fn () => LedgerEntry::query()->delete(),
    ] as $write) {
        Constraint::assertRejects($write, Constraint::RAISE_EXCEPTION, message: 'ledger_entries is append-only');
    }

    expect(DB::table('ledger_entries')->value('amount_nanos'))->toBe(1_000);
});

it('rejects usage records that break a CHECK constraint', function (array $overrides, string $constraint): void {
    Constraint::assertRejects(
        fn () => DB::table('usage_records')->insert(usageRow($this->customer, $overrides)),
        Constraint::CHECK_VIOLATION,
        $constraint,
    );
})->with([
    'class name as billable type' => [['billable_type' => 'App\\Models\\Take'], 'usage_records_billable_type_check'],
    'unknown provider' => [['provider' => 'openai'], 'usage_records_provider_check'],
    'unknown status' => [['status' => 'held'], 'usage_records_status_check'],
    'negative tokens' => [['output_tokens' => -1], 'usage_records_tokens_check'],
    'negative hold' => [['max_charge_nanos' => -1, 'min_charge_nanos' => -2], 'usage_records_amounts_check'],
    'min above max' => [['min_cost_nanos' => 300], 'usage_records_estimate_range_check'],
    'flags not an array' => [['flags' => '{"orphan": true}'], 'usage_records_flags_check'],
    'open with a charge' => [['provider_cost_nanos' => 100, 'charge_nanos' => 125], 'usage_records_open_check'],
    'settled without amounts' => [['status' => 'settled', 'billing_date' => '2026-11-17'], 'usage_records_settled_check'],
    'settled without billing date' => [['status' => 'settled', 'provider_cost_nanos' => 100, 'charge_nanos' => 125], 'usage_records_settled_check'],
    'voided with a charge' => [['status' => 'voided', 'provider_cost_nanos' => 0, 'charge_nanos' => 125], 'usage_records_voided_check'],
    'absorbed cost on an open usage' => [['absorbed_cost_nanos' => 100, 'billing_date' => '2026-11-17'], 'usage_records_absorbed_check'],
    'absorbed cost on a settled usage' => [['status' => 'settled', 'provider_cost_nanos' => 100, 'charge_nanos' => 125, 'absorbed_cost_nanos' => 100, 'billing_date' => '2026-11-17'], 'usage_records_absorbed_check'],
    'negative absorbed cost' => [['status' => 'voided', 'provider_cost_nanos' => 0, 'charge_nanos' => 0, 'absorbed_cost_nanos' => -1, 'billing_date' => '2026-11-17'], 'usage_records_absorbed_check'],
    'absorbed cost without a billing date' => [['status' => 'voided', 'provider_cost_nanos' => 0, 'charge_nanos' => 0, 'absorbed_cost_nanos' => 100], 'usage_records_absorbed_check'],
]);

it('allows one usage record per billable', function (): void {
    $row = usageRow($this->customer);
    DB::table('usage_records')->insert($row);

    Constraint::assertRejects(
        fn () => DB::table('usage_records')->insert([...$row, 'id' => (string) Str::ulid()]),
        Constraint::UNIQUE_VIOLATION,
        'usage_records_billable_type_billable_id_unique',
    );
});

it('rejects invalid purchases and replayed purchase keys', function (): void {
    $purchase = Purchase::factory()->create(['customer_id' => $this->customer->id]);

    Constraint::assertRejects(
        fn () => DB::table('purchases')->where('id', $purchase->id)->update(['amount_nanos' => 0]),
        Constraint::CHECK_VIOLATION,
        'purchases_amount_nanos_check',
    );

    Constraint::assertRejects(
        fn () => DB::table('purchases')->where('id', $purchase->id)->update(['paid_at' => null]),
        Constraint::CHECK_VIOLATION,
        'purchases_paid_has_paid_at_check',
    );

    Constraint::assertRejects(
        fn () => Purchase::factory()->create(['customer_id' => $this->customer->id, 'idempotency_key' => $purchase->idempotency_key]),
        Constraint::UNIQUE_VIOLATION,
        'purchases_customer_id_idempotency_key_unique',
    );
});

it('rejects ledger entries that point at another customer\'s usage or purchase', function (): void {
    $other = Customer::factory()->create();
    $otherUsage = UsageRecord::factory()->settled()->create(['customer_id' => $other->id]);
    $otherPurchase = Purchase::factory()->create(['customer_id' => $other->id]);

    Constraint::assertRejects(
        fn () => DB::table('ledger_entries')->insert(ledgerRow($this->customer, ['type' => 'charge', 'amount_nanos' => -375, 'usage_record_id' => $otherUsage->id])),
        Constraint::FOREIGN_KEY_VIOLATION,
        'ledger_entries_usage_record_id_customer_id_foreign',
    );

    Constraint::assertRejects(
        fn () => DB::table('ledger_entries')->insert(ledgerRow($this->customer, ['type' => 'purchase', 'amount_nanos' => 5_000_000_000, 'purchase_id' => $otherPurchase->id])),
        Constraint::FOREIGN_KEY_VIOLATION,
        'ledger_entries_purchase_id_customer_id_foreign',
    );

    // The owner's own entries are accepted.
    DB::table('ledger_entries')->insert(ledgerRow($other, ['type' => 'charge', 'amount_nanos' => -375, 'usage_record_id' => $otherUsage->id]));
    DB::table('ledger_entries')->insert(ledgerRow($other, ['type' => 'purchase', 'amount_nanos' => 5_000_000_000, 'purchase_id' => $otherPurchase->id]));
});

it('blocks deleting a customer who has takes or ledger entries', function (): void {
    $withTakes = Take::factory()->create()->customer;
    $withLedger = LedgerEntry::factory()->welcome()->create()->customer;

    Constraint::assertRejects(fn () => $withTakes->delete(), Constraint::FOREIGN_KEY_VIOLATION);
    Constraint::assertRejects(fn () => $withLedger->delete(), Constraint::FOREIGN_KEY_VIOLATION, 'ledger_entries_customer_id_foreign');

    expect(Customer::query()->whereKey([$withTakes->id, $withLedger->id])->count())->toBe(2);
});

/**
 * Only webhook deliveries hold an FK to a take. The usage record points at it
 * through a morph pair with no FK, which is why takes are never hard-deleted
 * (documented on the takes migration and the Take model).
 */
it('blocks deleting a take that webhook deliveries point to', function (): void {
    $take = Take::factory()->succeeded()->create();
    DB::table('webhook_deliveries')->insert([
        'event_id' => 'evt_1',
        'take_id' => $take->id,
        'type' => 'generation.succeeded',
        'attempt' => 1,
        'outcome' => 'applied',
        'received_at' => now(),
    ]);

    Constraint::assertRejects(fn () => $take->delete(), Constraint::FOREIGN_KEY_VIOLATION, 'webhook_deliveries_take_id_foreign');
});
