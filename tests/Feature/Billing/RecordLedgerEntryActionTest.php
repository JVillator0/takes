<?php

declare(strict_types=1);

use App\Actions\Billing\RecordLedgerEntryAction;
use App\Enums\Billing\LedgerEntryType;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Illuminate\Support\Facades\DB;
use Tests\Support\Billing;

function recordEntry(Customer $customer, LedgerEntryType $type, int $nanos, ?UsageRecord $usage = null, ?Purchase $purchase = null): LedgerEntry
{
    return DB::transaction(fn (): LedgerEntry => app(RecordLedgerEntryAction::class)->execute(
        customer: $customer,
        type: $type,
        amount: Nanodollars::fromInt($nanos),
        usage: $usage,
        purchase: $purchase,
        reason: 'test',
    ));
}

it('appends one entry and moves the cached balance by exactly its amount', function (): void {
    $customer = Customer::factory()->create();

    recordEntry($customer, LedgerEntryType::PromoGrant, 1_000_000_000);
    $entry = recordEntry($customer, LedgerEntryType::Adjustment, -1);

    expect($entry->amount_nanos)->toBe(-1)
        ->and($entry->reason)->toBe('test')
        // The caller's model is kept in step, and the database agrees.
        ->and($customer->balance_nanos)->toBe(999_999_999)
        ->and($customer->isDirty())->toBeFalse()
        ->and($customer->refresh()->balance_nanos)->toBe(999_999_999);

    Billing::assertCachedColumnsMatch($customer);
});

it('rejects an amount with the wrong sign before writing', function (LedgerEntryType $type, int $nanos): void {
    $customer = Customer::factory()->create();

    expect(fn () => recordEntry($customer, $type, $nanos))->toThrow(InvalidArgumentException::class);
    expect(LedgerEntry::query()->count())->toBe(0)
        ->and($customer->refresh()->balance_nanos)->toBe(0);
})->with([
    'zero promo grant' => [LedgerEntryType::PromoGrant, 0],
    'negative promo grant' => [LedgerEntryType::PromoGrant, -1],
    'positive charge' => [LedgerEntryType::Charge, 1],
    'zero adjustment' => [LedgerEntryType::Adjustment, 0],
]);

it('requires the usage for a charge and the purchase for a purchase entry', function (LedgerEntryType $type, int $nanos): void {
    recordEntry(Customer::factory()->create(), $type, $nanos);
})->throws(InvalidArgumentException::class)->with([
    'charge' => [LedgerEntryType::Charge, -1],
    'purchase' => [LedgerEntryType::Purchase, 1],
]);

it('refuses a usage or purchase of another customer', function (): void {
    $customer = Customer::factory()->create();
    $otherPurchase = Purchase::factory()->create();

    recordEntry($customer, LedgerEntryType::Purchase, 5_000_000_000, purchase: $otherPurchase);
})->throws(InvalidArgumentException::class, 'another customer');
