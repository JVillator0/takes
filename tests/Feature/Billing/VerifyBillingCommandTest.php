<?php

declare(strict_types=1);

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Billing\PurchaseCreditsAction;
use App\Actions\Billing\VoidUsageAction;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Billing\Provider;
use App\Enums\Takes\TakeStatus;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\Suggestion;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Billing;
use Tests\Support\PixelforgeFixture;

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

/**
 * An orphan success (D-025): a voided usage, nothing charged, whose provider
 * cost was absorbed on $billingDate (as ApplyTakeSnapshotAction records it).
 */
function orphanUsage(int $absorbedCost, string $billingDate): void
{
    $usage = DB::transaction(fn () => app(VoidUsageAction::class)->execute(Billing::openForNewTake(Billing::fundedCustomer())));
    DB::table('usage_records')->where('id', $usage->id)->update([
        'flags' => json_encode(['orphan']),
        'absorbed_cost_nanos' => $absorbedCost,
        'completed_at' => $billingDate.' 12:00:00+00',
        'billing_date' => $billingDate,
    ]);
}

/**
 * Fakes GET /v1/usage: one row per requested day, costs (nanodollars) from $costs.
 *
 * @param  array<string, int>  $costs  keyed by 'Y-m-d'
 */
function fakePixelforgeUsage(array $costs): void
{
    config(['pixelforge.base_url' => 'https://pixelforge.test/v1', 'pixelforge.api_key' => 'pf_test_0123456789abcdef']);
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/usage*' => function (Request $request) use ($costs) {
        $start = CarbonImmutable::parse((string) $request->data()['start_date'], 'UTC');
        $end = CarbonImmutable::parse((string) $request->data()['end_date'], 'UTC');
        $days = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'generations' => 0,
                'input_text_tokens' => 0,
                'input_image_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'cost_usd' => Nanodollars::fromInt($costs[$day->toDateString()] ?? 0)->toDecimalString(),
            ];
        }

        return Http::response([
            'object' => 'usage',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'data' => $days,
            'total' => [],
        ]);
    }]);
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

it('fails when a suggestion whose AI call is over still holds an open usage (#102)', function (string $status): void {
    $customer = Billing::fundedCustomer();
    $suggestion = Suggestion::factory()->create(['customer_id' => $customer->id]);
    $usage = DB::transaction(fn () => app(OpenUsageAction::class)->execute(
        $suggestion,
        Provider::Anthropic,
        Billing::estimate(1_000_000),
        Billing::anthropicRates(),
        'claude-haiku-5-5',
    ));
    DB::table('suggestions')->where('id', $suggestion->id)->update(['status' => $status]);

    $this->artisan('billing:verify')
        ->expectsOutputToContain("usage {$usage->id}: still open but suggestion {$suggestion->id} is {$status}")
        ->assertFailed();
})->with(['proposed', 'applied', 'discarded', 'failed']);

it('accepts the open usage of a pending suggestion', function (): void {
    $customer = Billing::fundedCustomer();
    $suggestion = Suggestion::factory()->create(['customer_id' => $customer->id]);
    DB::transaction(fn () => app(OpenUsageAction::class)->execute(
        $suggestion,
        Provider::Anthropic,
        Billing::estimate(1_000_000),
        Billing::anthropicRates(),
        'claude-haiku-5-5',
    ));

    $this->artisan('billing:verify')
        ->expectsOutput('Billing invariants hold.')
        ->assertSuccessful();
});

describe('--usage', function (): void {
    it('refuses --from or --to without --usage instead of ignoring the range', function (string $options): void {
        cleanDataset();
        Http::preventStrayRequests();

        $this->artisan("billing:verify {$options}")
            ->expectsOutputToContain('--from and --to only apply to --usage')
            ->doesntExpectOutputToContain('Billing invariants hold.')
            ->assertFailed();
    })->with([
        'both' => ['--from=2026-11-01 --to=2026-11-15'],
        'only --from' => ['--from=2026-11-01'],
        'only --to' => ['--to=2026-11-15'],
        'bare --from' => ['--from'],
        'bare --to' => ['--to'],
        'empty --from' => ['--from='],
    ]);

    it('fails, never passes, when Pixelforge has no API key', function (): void {
        config(['pixelforge.api_key' => null]);
        Http::preventStrayRequests();

        $this->artisan('billing:verify --usage')
            ->expectsOutputToContain('--usage: Pixelforge usage is unavailable (PixelforgeNotConfigured')
            ->assertFailed();
    });

    it('fails, never passes, when Pixelforge cannot answer', function (): void {
        config(['pixelforge.base_url' => 'https://pixelforge.test/v1', 'pixelforge.api_key' => 'pf_test_0123456789abcdef']);
        Http::preventStrayRequests();
        Http::fake(['pixelforge.test/v1/usage*' => Http::response(PixelforgeFixture::error('overloaded'), 503)]);

        $this->artisan('billing:verify --usage --from=2026-11-15 --to=2026-11-18')
            ->expectsOutputToContain('--usage: Pixelforge usage is unavailable')
            ->assertFailed();
    });

    it('refuses a date that is not a real Y-m-d day', function (string $option): void {
        fakePixelforgeUsage([]);

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

    it('refuses --from or --to given without a value instead of using the default', function (string $option): void {
        fakePixelforgeUsage([]);

        $this->artisan("billing:verify --usage {$option}")
            ->expectsOutputToContain("{$option} needs a Y-m-d date, got none.")
            ->doesntExpectOutputToContain('matches')
            ->assertFailed();
    })->with(['--from', '--to']);

    it('refuses an inverted range instead of passing vacuously', function (): void {
        fakePixelforgeUsage([]);

        $this->artisan('billing:verify --usage --from=2026-11-18 --to=2026-11-15')
            ->expectsOutputToContain('--from 2026-11-18 is after --to 2026-11-15.')
            ->doesntExpectOutputToContain('matches')
            ->assertFailed();
    });

    it('passes when the per-day cost matches Pixelforge', function (): void {
        Billing::settle(Billing::openForNewTake(Billing::fundedCustomer()));
        fakePixelforgeUsage(['2026-11-17' => Billing::EXAMPLE_COST]);

        $this->artisan('billing:verify --usage --from=2026-11-15 --to=2026-11-18')
            ->expectsOutputToContain('Pixelforge usage matches from 2026-11-15 to 2026-11-18.')
            ->assertSuccessful();
    });

    it('attributes an orphan success to its own line instead of reporting drift (#102)', function (): void {
        Billing::settle(Billing::openForNewTake(Billing::fundedCustomer()));
        orphanUsage(Billing::EXAMPLE_COST, '2026-11-16');
        orphanUsage(7, '2026-11-17');
        fakePixelforgeUsage(['2026-11-16' => Billing::EXAMPLE_COST, '2026-11-17' => Billing::EXAMPLE_COST + 7]);

        $this->artisan('billing:verify --usage --from=2026-11-15 --to=2026-11-18')
            ->expectsOutputToContain('usage 2026-11-16: Orphan success (cost absorbed) $0.268375')
            ->expectsOutputToContain('usage 2026-11-17: Orphan success (cost absorbed) $0.000000007')
            ->expectsOutputToContain('Pixelforge usage matches from 2026-11-15 to 2026-11-18.')
            ->assertSuccessful();
    });

    it('still fails an orphan day whose total differs, naming the absorbed part', function (): void {
        orphanUsage(Billing::EXAMPLE_COST, '2026-11-16');
        fakePixelforgeUsage(['2026-11-16' => Billing::EXAMPLE_COST + 1]);

        $this->artisan('billing:verify --usage --from=2026-11-15 --to=2026-11-18')
            ->expectsOutputToContain('usage 2026-11-16: ledger cost $0.00 + absorbed orphan cost $0.268375 but Pixelforge reports $0.268375001')
            ->assertFailed();
    });

    it('fails on the day the costs differ', function (): void {
        Billing::settle(Billing::openForNewTake(Billing::fundedCustomer()));
        fakePixelforgeUsage(['2026-11-17' => Billing::EXAMPLE_COST + 1, '2026-11-16' => 5]);

        $this->artisan('billing:verify --usage --from=2026-11-15 --to=2026-11-18')
            ->expectsOutputToContain('usage 2026-11-16: ledger cost $0.00 but Pixelforge reports $0.000000005')
            ->expectsOutputToContain('usage 2026-11-17: ledger cost $0.268375 but Pixelforge reports $0.268375001')
            ->assertFailed();
    });
});
