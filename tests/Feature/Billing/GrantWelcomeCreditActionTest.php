<?php

declare(strict_types=1);

use App\Actions\Billing\GrantWelcomeCreditAction;
use App\Enums\Billing\LedgerEntryType;
use App\Models\Customer;
use App\Models\LedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Support\Billing;

it('grants $1.00 as a welcome promo_grant by default (D-019)', function (): void {
    $customer = Customer::factory()->create();

    $entry = app(GrantWelcomeCreditAction::class)->execute($customer);

    expect($entry->type)->toBe(LedgerEntryType::PromoGrant)
        ->and($entry->reason)->toBe('welcome')
        ->and($entry->amount_nanos)->toBe(1_000_000_000)
        ->and($customer->refresh()->balance_nanos)->toBe(1_000_000_000);

    Billing::assertCachedColumnsMatch($customer);
});

it('reads the amount from takes.welcome_credit_nanos when configured', function (): void {
    config(['takes.welcome_credit_nanos' => 2_500_000_000]);
    $customer = Customer::factory()->create();

    app(GrantWelcomeCreditAction::class)->execute($customer);

    expect($customer->refresh()->balance_nanos)->toBe(2_500_000_000);
});

it('grants once per customer: a second call returns the first grant', function (): void {
    $customer = Customer::factory()->create();

    $first = app(GrantWelcomeCreditAction::class)->execute($customer);
    $second = app(GrantWelcomeCreditAction::class)->execute($customer);

    expect($second->id)->toBe($first->id)
        ->and(LedgerEntry::query()->where('customer_id', $customer->id)->count())->toBe(1)
        ->and($customer->refresh()->balance_nanos)->toBe(1_000_000_000);
});

it('is backed by the unique welcome index', function (): void {
    $customer = Customer::factory()->create();
    LedgerEntry::factory()->welcome()->create(['customer_id' => $customer->id]);

    LedgerEntry::factory()->welcome()->create(['customer_id' => $customer->id]);
})->throws(UniqueConstraintViolationException::class);
