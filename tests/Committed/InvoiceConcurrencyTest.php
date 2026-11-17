<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\Billing;

/*
 * Real processes race on the invoice counter (spec 6 Tests). Rows are
 * committed (DatabaseMigrations, see tests/Pest.php) so the workers see them.
 * Group `concurrency`: run serially, never with --parallel.
 */

/**
 * Runs one purchase worker per argument list, all starting at the same instant.
 *
 * @param  list<array{customer: Customer, usd: int, key: string, now?: string}>  $purchases
 * @return list<array{purchase: string, number: string}>
 */
function racePurchases(array $purchases): array
{
    $connection = config('database.connections.'.config('database.default'));
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => (string) config('database.default'),
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
    ];
    // Time for every process to boot before the start line.
    $startAt = (string) (microtime(true) + 2.5);

    $results = Process::pool(function (Pool $pool) use ($purchases, $env, $startAt): void {
        foreach ($purchases as $index => $purchase) {
            $pool->as((string) $index)
                ->env($env)
                ->timeout(60)
                ->command(array_values(array_filter([
                    PHP_BINARY,
                    base_path('tests/Committed/purchase-worker.php'),
                    (string) $purchase['customer']->id,
                    (string) $purchase['usd'],
                    $purchase['key'],
                    $startAt,
                    '300',
                    $purchase['now'] ?? null,
                ])));
        }
    })->start()->wait();

    return array_map(function (int $index) use ($results): array {
        $result = $results[(string) $index];

        expect($result->exitCode())->not->toBe(3, 'A worker missed the start line: the processes did not race.')
            ->and($result->successful())->toBeTrue($result->errorOutput());

        [$purchase, $number] = explode(' ', trim($result->output()));

        return ['purchase' => $purchase, 'number' => $number];
    }, array_keys($purchases));
}

it('gives concurrent purchases of different customers consecutive, gapless numbers', function (): void {
    DB::table('invoice_counters')->insert(['year' => 2026, 'last_number' => 3]);
    $customers = Customer::factory()->count(4)->create();

    $outputs = racePurchases($customers->map(fn (Customer $customer): array => [
        'customer' => $customer,
        'usd' => 5,
        'key' => (string) Str::uuid(),
        'now' => '2026-11-17T10:02:00Z',
    ])->all());

    $numbers = array_column($outputs, 'number');
    sort($numbers);

    expect($numbers)->toBe(['INV-2026-000004', 'INV-2026-000005', 'INV-2026-000006', 'INV-2026-000007'])
        ->and(Invoice::query()->count())->toBe(4)
        ->and(DB::table('invoice_counters')->where('year', 2026)->value('last_number'))->toBe(7);

    $this->artisan('billing:verify')->assertSuccessful();
})->group('concurrency');

it('numbers the first purchases of a new year from 1 when they race for the counter row', function (): void {
    // Last year's counter exists; this year's row does not until a purchase creates it.
    DB::table('invoice_counters')->insert(['year' => 2026, 'last_number' => 182]);
    $customers = Customer::factory()->count(3)->create();

    $outputs = racePurchases($customers->map(fn (Customer $customer): array => [
        'customer' => $customer,
        'usd' => 10,
        'key' => (string) Str::uuid(),
        'now' => '2027-01-01T00:00:05Z',
    ])->all());

    $numbers = array_column($outputs, 'number');
    sort($numbers);

    expect($numbers)->toBe(['INV-2027-000001', 'INV-2027-000002', 'INV-2027-000003'])
        ->and(DB::table('invoice_counters')->orderBy('year')->pluck('last_number', 'year')->all())->toBe([2026 => 182, 2027 => 3]);
})->group('concurrency');

it('creates one purchase, one ledger entry and one invoice for a double-click with the same key', function (): void {
    $customer = Customer::factory()->create();
    $key = (string) Str::uuid();

    $outputs = racePurchases([
        ['customer' => $customer, 'usd' => 25, 'key' => $key],
        ['customer' => $customer, 'usd' => 25, 'key' => $key],
    ]);

    // The second request waits on the customer lock, then replays the first.
    expect($outputs[0])->toBe($outputs[1])
        ->and(Purchase::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and($customer->refresh()->balance_nanos)->toBe(25_000_000_000);

    Billing::assertCachedColumnsMatch($customer);
})->group('concurrency');

it('lets two customers use the same key at the same time', function (): void {
    $customers = Customer::factory()->count(2)->create();
    $key = (string) Str::uuid();

    $outputs = racePurchases($customers->map(fn (Customer $customer): array => [
        'customer' => $customer,
        'usd' => 5,
        'key' => $key,
    ])->all());

    expect($outputs[0]['purchase'])->not->toBe($outputs[1]['purchase'])
        ->and($outputs[0]['number'])->not->toBe($outputs[1]['number'])
        ->and(Purchase::query()->count())->toBe(2)
        ->and(Invoice::query()->count())->toBe(2);
})->group('concurrency');
