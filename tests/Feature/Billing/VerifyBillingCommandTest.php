<?php

declare(strict_types=1);

use App\Actions\Billing\PurchaseCreditsAction;
use App\Actions\Billing\VoidUsageAction;
use App\Contracts\Billing\PixelforgeUsageSource;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Takes\TakeStatus;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Billing;

/*
 * billing:verify (D-059): passes on a clean dataset, exits 1 on any drift.
 * Drift is injected with raw SQL, the way a bug outside the actions would.
 */

/** A clean dataset: purchase, welcome, settled, voided and open usages. */
function cleanDataset(): Customer
{
    $customer = Billing::fundedCustomer(1_000_000_000);
    app(PurchaseCreditsAction::class)->execute($customer, 5, (string) Str::uuid());
    Billing::settle(Billing::openForNewTake($customer));
    Billing::settle(Billing::openForNewTake($customer), new UsageUnitsDTO([]));
    DB::transaction(fn () => app(VoidUsageAction::class)->execute(Billing::openForNewTake($customer)));
    Billing::openForNewTake($customer);
    Customer::factory()->create();

    return $customer->refresh();
}

function fakeUsageSource(array $costs): void
{
    app()->instance(PixelforgeUsageSource::class, new class($costs) implements PixelforgeUsageSource
    {
        /** @param array<string, int> $costs */
        public function __construct(private array $costs) {}

        public function dailyCosts(CarbonImmutable $from, CarbonImmutable $to): array
        {
            return array_map(Nanodollars::fromInt(...), $this->costs);
        }
    });
}

it('passes on a clean dataset', function (): void {
    cleanDataset();

    $this->artisan('billing:verify')
        ->expectsOutput('Billing invariants hold.')
        ->assertSuccessful();
});

it('fails when a balance drifts from the ledger', function (): void {
    $customer = cleanDataset();
    DB::table('customers')->where('id', $customer->id)->increment('balance_nanos');

    $this->artisan('billing:verify')
        ->expectsOutputToContain("customer {$customer->id}: balance_nanos")
        ->assertFailed();
});

it('fails when held drifts from the open holds', function (): void {
    $customer = cleanDataset();
    DB::table('customers')->where('id', $customer->id)->decrement('held_nanos', 1);

    $this->artisan('billing:verify')
        ->expectsOutputToContain("customer {$customer->id}: held_nanos")
        ->assertFailed();
});

it('fails when a settled usage has no charge entry', function (): void {
    $customer = Billing::fundedCustomer();
    $usage = Billing::openForNewTake($customer);
    // A settlement that never reached the ledger (the ledger is append-only, so
    // the drift is injected on the usage side). The hold is released too, so
    // only the per-usage check can see it.
    DB::table('usage_records')->where('id', $usage->id)->update([
        'status' => 'settled',
        'units' => json_encode(Billing::exampleUnits()->tokens),
        'provider_cost_nanos' => Billing::EXAMPLE_COST,
        'charge_nanos' => Billing::EXAMPLE_CHARGE,
        'completed_at' => now(),
        'billing_date' => now()->toDateString(),
    ]);
    DB::table('customers')->where('id', $customer->id)->decrement('held_nanos', Billing::DEFAULT_MAX_CHARGE);

    $this->artisan('billing:verify')
        ->expectsOutputToContain("usage {$usage->id} (settled, charge_nanos ".Billing::EXAMPLE_CHARGE.'): 0 charge entries')
        ->doesntExpectOutputToContain('held_nanos')
        ->assertFailed();
});

it('fails when a voided usage was charged', function (): void {
    $customer = Billing::fundedCustomer();
    $usage = DB::transaction(fn () => app(VoidUsageAction::class)->execute(Billing::openForNewTake($customer)));
    LedgerEntry::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'charge',
        'amount_nanos' => -1,
        'usage_record_id' => $usage->id,
    ]);
    DB::table('customers')->where('id', $customer->id)->decrement('balance_nanos', 1);

    $this->artisan('billing:verify')
        ->expectsOutputToContain("usage {$usage->id} (voided")
        ->assertFailed();
});

it('fails when a settled charge is not cost + markup', function (): void {
    $usage = Billing::settle(Billing::openForNewTake(Billing::fundedCustomer()));
    DB::table('usage_records')->where('id', $usage->id)->update(['provider_cost_nanos' => Billing::EXAMPLE_COST - 1]);

    $this->artisan('billing:verify')
        ->expectsOutputToContain("usage {$usage->id}: provider_cost_nanos")
        ->expectsOutputToContain("usage {$usage->id}: charge_nanos")
        ->assertFailed();
});

it('fails when a paid purchase has no ledger entry', function (): void {
    $purchase = Purchase::factory()->create();

    $this->artisan('billing:verify')
        ->expectsOutputToContain("purchase {$purchase->id} (paid): 0 purchase entries")
        ->assertFailed();
});

it('fails when a terminal take still holds an open usage', function (): void {
    $usage = Billing::openForNewTake(Billing::fundedCustomer());
    DB::table('takes')->where('id', $usage->billable_id)->update([
        'status' => TakeStatus::Failed->value,
        'failure_code' => 'generation_failed',
        'completed_at' => now(),
    ]);

    $this->artisan('billing:verify')
        ->expectsOutputToContain("usage {$usage->id}: still open but take {$usage->billable_id} is failed")
        ->assertFailed();
});

describe('--usage', function (): void {
    it('fails with "not configured" until the Pixelforge client binds a source', function (): void {
        $this->artisan('billing:verify --usage')
            ->expectsOutputToContain('Pixelforge usage source is not configured.')
            ->assertFailed();
    });

    it('refuses a date that is not a real Y-m-d day', function (string $option): void {
        fakeUsageSource([]);

        $this->artisan("billing:verify --usage {$option}")
            ->expectsOutputToContain('must be a real date in Y-m-d format')
            ->doesntExpectOutputToContain('matches')
            ->assertFailed();
    })->with([
        'rolls over' => ['--from=2026-02-30 --to=2026-03-05'],
        'not a date' => ['--to=yesterday'],
        'wrong format' => ['--from=17/11/2026'],
        'empty' => ['--from='],
    ]);

    it('refuses an inverted range instead of passing vacuously', function (): void {
        fakeUsageSource([]);

        $this->artisan('billing:verify --usage --from=2026-11-18 --to=2026-11-15')
            ->expectsOutputToContain('--from 2026-11-18 is after --to 2026-11-15.')
            ->doesntExpectOutputToContain('matches')
            ->assertFailed();
    });

    it('passes when the per-day cost matches Pixelforge', function (): void {
        Billing::settle(Billing::openForNewTake(Billing::fundedCustomer()));
        fakeUsageSource(['2026-11-17' => Billing::EXAMPLE_COST]);

        $this->artisan('billing:verify --usage --from=2026-11-15 --to=2026-11-18')
            ->expectsOutputToContain('Pixelforge usage matches from 2026-11-15 to 2026-11-18.')
            ->assertSuccessful();
    });

    it('fails on the day the costs differ', function (): void {
        Billing::settle(Billing::openForNewTake(Billing::fundedCustomer()));
        fakeUsageSource(['2026-11-17' => Billing::EXAMPLE_COST + 1, '2026-11-16' => 5]);

        $this->artisan('billing:verify --usage --from=2026-11-15 --to=2026-11-18')
            ->expectsOutputToContain('usage 2026-11-16: ledger cost $0.00 but Pixelforge reports $0.000000005')
            ->expectsOutputToContain('usage 2026-11-17: ledger cost $0.268375 but Pixelforge reports $0.268375001')
            ->assertFailed();
    });
});
