<?php

declare(strict_types=1);

use App\Enums\Billing\UsageStatus;
use App\Models\LedgerEntry;
use App\Models\Take;
use App\Models\UsageRecord;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Tests\Support\Billing;

/*
 * Two real processes race on one customer (spec 3-03 Tests, "Concurrency").
 * Rows are committed (DatabaseMigrations, see tests/Pest.php) so the workers
 * see them. Run serially: never with --parallel.
 */

/**
 * Runs the worker once per argument list, all starting at the same instant.
 *
 * @param  list<list<string>>  $runs
 * @return list<string> each worker's output
 */
function race(array $runs): array
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
    // Time for both processes to boot before the start line.
    $startAt = (string) (microtime(true) + 2.0);

    $results = Process::pool(function (Pool $pool) use ($runs, $env, $startAt): void {
        foreach ($runs as $index => $arguments) {
            $pool->as((string) $index)
                ->env($env)
                ->timeout(60)
                ->command([PHP_BINARY, base_path('tests/Committed/worker.php'), $arguments[0], $arguments[1], $startAt, '500', ...array_slice($arguments, 2)]);
        }
    })->start()->wait();

    return array_map(function (string $index) use ($results): string {
        $result = $results[$index];

        expect($result->successful())->toBeTrue($result->errorOutput());

        return trim($result->output());
    }, array_map('strval', array_keys($runs)));
}

it('lets exactly one of two parallel opens through when the balance covers one', function (): void {
    $customer = Billing::fundedCustomer(Billing::DEFAULT_MAX_CHARGE);
    $takes = Take::factory()->count(2)->create(['customer_id' => $customer->id]);

    $outputs = race([
        ['open', $takes[0]->id, (string) Billing::DEFAULT_MAX_COST],
        ['open', $takes[1]->id, (string) Billing::DEFAULT_MAX_COST],
    ]);

    sort($outputs);

    expect($outputs)->toBe(['insufficient', 'opened'])
        ->and(UsageRecord::query()->where('customer_id', $customer->id)->where('status', UsageStatus::Open)->count())->toBe(1)
        ->and($customer->refresh()->held_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE)
        ->and($customer->available()->toInt())->toBe(0);

    Billing::assertCachedColumnsMatch($customer);
    $this->artisan('billing:verify')->assertSuccessful();
})->group('concurrency');

it('keeps the balance exact when two usages of one customer settle in parallel', function (): void {
    $customer = Billing::fundedCustomer(10_000_000_000);
    $first = Billing::openForNewTake($customer);
    $second = Billing::openForNewTake($customer);

    $outputs = race([
        ['settle', $first->id],
        ['settle', $second->id],
    ]);

    expect($outputs)->toBe(['settled', 'settled'])
        ->and($customer->refresh()->balance_nanos)->toBe(10_000_000_000 - 2 * Billing::EXAMPLE_CHARGE)
        ->and($customer->held_nanos)->toBe(0)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(2);

    Billing::assertCachedColumnsMatch($customer);
    $this->artisan('billing:verify')->assertSuccessful();
})->group('concurrency');

it('charges once when the same usage is settled by two processes at once', function (): void {
    $customer = Billing::fundedCustomer(10_000_000_000);
    $usage = Billing::openForNewTake($customer);

    race([
        ['settle', $usage->id],
        ['settle', $usage->id],
    ]);

    expect(LedgerEntry::query()->where('usage_record_id', $usage->id)->count())->toBe(1)
        ->and($customer->refresh()->balance_nanos)->toBe(10_000_000_000 - Billing::EXAMPLE_CHARGE)
        ->and($customer->held_nanos)->toBe(0);

    $this->artisan('billing:verify')->assertSuccessful();
})->group('concurrency');
