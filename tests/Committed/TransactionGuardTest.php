<?php

declare(strict_types=1);

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Billing\RecordLedgerEntryAction;
use App\Actions\Billing\SettleUsageAction;
use App\Actions\Billing\VoidUsageAction;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\Provider;
use App\Exceptions\Billing\TransactionRequiredException;
use App\Models\Customer;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Billing;

/*
 * The money actions run in the caller's transaction (spec 3-03 §6). These
 * tests need a real transaction level of 0, so they live outside the
 * RefreshDatabase suite (which wraps every test in a transaction).
 */

beforeEach(function (): void {
    expect(DB::transactionLevel())->toBe(0);
});

it('refuses to run outside a transaction', function (string $action): void {
    match ($action) {
        'record' => app(RecordLedgerEntryAction::class)->execute(
            Customer::factory()->create(),
            LedgerEntryType::PromoGrant,
            Nanodollars::fromInt(1),
        ),
        'open' => app(OpenUsageAction::class)->execute(
            Take::factory()->create(),
            Provider::Pixelforge,
            Billing::estimate(),
            Billing::pixelforgeRates(),
        ),
        'settle' => app(SettleUsageAction::class)->execute(
            UsageRecord::factory()->create(),
            Billing::exampleUnits(),
            Billing::pixelforgeRates(),
            CarbonImmutable::now(),
        ),
        'void' => app(VoidUsageAction::class)->execute(UsageRecord::factory()->create()),
    };
})->throws(TransactionRequiredException::class)->with(['record', 'open', 'settle', 'void']);

it('writes nothing when it refuses', function (): void {
    $customer = Customer::factory()->create();

    try {
        app(RecordLedgerEntryAction::class)->execute($customer, LedgerEntryType::PromoGrant, Nanodollars::fromInt(1));
    } catch (TransactionRequiredException) {
    }

    expect($customer->refresh()->balance_nanos)->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});
