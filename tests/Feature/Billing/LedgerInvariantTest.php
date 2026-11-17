<?php

declare(strict_types=1);

use App\Actions\Billing\GrantWelcomeCreditAction;
use App\Actions\Billing\PurchaseCreditsAction;
use App\Actions\Billing\RecordLedgerEntryAction;
use App\Actions\Billing\VoidUsageAction;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\UsageStatus;
use App\Exceptions\Billing\InsufficientCreditsException;
use App\Models\Customer;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Support\Billing;

/*
 * Invariant (spec 3-03 Tests): after any sequence of money operations,
 * Σ ledger == balance and Σ open max_charge == held for every customer,
 * and billing:verify agrees. Seeded, so a failure is reproducible.
 */

it('keeps Σ ledger == balance and Σ open holds == held over a randomized sequence', function (int $seed): void {
    $random = new Randomizer(new Xoshiro256StarStar($seed));
    $customers = Customer::factory()->count(3)->create()->all();
    $insufficient = 0;

    for ($step = 0; $step < 150; $step++) {
        $customer = $customers[$random->getInt(0, 2)];
        // Every pick goes through the seeded Randomizer over a list in a fixed
        // order (ULIDs are monotonic within the process), never heap order or
        // the database's random(), so a seed replays the exact sequence.
        $open = UsageRecord::query()->where('customer_id', $customer->id)->where('status', UsageStatus::Open)->orderBy('id')->get();
        $closed = UsageRecord::query()->where('customer_id', $customer->id)->where('status', '<>', UsageStatus::Open)->orderBy('id')->get();

        match ($random->getInt(0, 7)) {
            0 => app(PurchaseCreditsAction::class)->execute($customer, [5, 10, 25][$random->getInt(0, 2)], (string) Str::uuid()),
            1 => app(GrantWelcomeCreditAction::class)->execute($customer),
            2 => DB::transaction(fn () => app(RecordLedgerEntryAction::class)->execute(
                customer: $customer,
                type: LedgerEntryType::Adjustment,
                amount: Nanodollars::fromInt($random->getInt(1, 50_000_000) * ($random->getInt(0, 1) === 1 ? 1 : -1)),
                reason: 'randomized',
            )),
            3, 4 => (function () use ($customer, $random, &$insufficient): void {
                try {
                    Billing::openForNewTake($customer, $random->getInt(0, 600_000_000));
                } catch (InsufficientCreditsException) {
                    $insufficient++;
                }
            })(),
            5 => $open->isEmpty() ? null : Billing::settle(
                $open[$random->getInt(0, $open->count() - 1)],
                // Sometimes above the hold, sometimes zero tokens.
                new UsageUnitsDTO(['input_text' => $random->getInt(0, 500), 'output' => $random->getInt(0, 12_000)]),
            ),
            6 => $open->isEmpty() ? null : DB::transaction(fn () => app(VoidUsageAction::class)->execute($open[$random->getInt(0, $open->count() - 1)])),
            // Replays: settling or voiding an already closed usage changes nothing.
            7 => $closed->isEmpty() ? null : (function (UsageRecord $replayed) use ($random): void {
                $random->getInt(0, 1) === 1
                    ? Billing::settle($replayed)
                    : DB::transaction(fn () => app(VoidUsageAction::class)->execute($replayed));
            })($closed[$random->getInt(0, $closed->count() - 1)]),
        };

        foreach ($customers as $each) {
            Billing::assertCachedColumnsMatch($each);
        }
    }

    expect(UsageRecord::query()->count())->toBeGreaterThan(10);
    $this->artisan('billing:verify')->assertSuccessful();
})->with([20261117, 7, 424242]);
