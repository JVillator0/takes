<?php

declare(strict_types=1);

use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Models\LedgerEntry;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Tests\Support\Billing;
use Tests\Support\Takes;

/*
 * Two real processes apply the same success to one take at once (spec 3-02
 * issue 3: settle exactly once). Rows are committed (DatabaseMigrations, see
 * tests/Pest.php) so the workers see them. Run serially: never with --parallel.
 */

it('charges once when two processes apply the same success in parallel', function (): void {
    $take = Takes::pendingWithHold();
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
    // Time for both processes to boot before the start line (as ConcurrencyTest).
    $startAt = (string) (microtime(true) + 3.0);

    $results = Process::pool(function (Pool $pool) use ($take, $env, $startAt): void {
        foreach (['first', 'second'] as $name) {
            $pool->as($name)
                ->env($env)
                ->timeout(60)
                ->command([PHP_BINARY, base_path('tests/Committed/apply-worker.php'), $take->id, $startAt, '500']);
        }
    })->start()->wait();

    $outcomes = [];

    foreach (['first', 'second'] as $name) {
        expect($results[$name]->exitCode())->not->toBe(3, 'A worker missed the start line: the processes did not race.')
            ->and($results[$name]->successful())->toBeTrue($results[$name]->errorOutput());
        $outcomes[] = trim($results[$name]->output());
    }

    sort($outcomes);
    $customer = $take->customer->refresh();

    // The loser waited on the take lock, then saw it succeeded.
    expect($outcomes)->toBe(['applied', 'ignored_same_state'])
        ->and($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Settled)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(1)
        ->and($customer->balance_nanos)->toBe(Takes::FUNDED_NANOS - Takes::DOCS_CHARGE)
        ->and($customer->held_nanos)->toBe(0);

    Billing::assertCachedColumnsMatch($customer);
    $this->artisan('billing:verify')->assertSuccessful();
})->group('concurrency');
