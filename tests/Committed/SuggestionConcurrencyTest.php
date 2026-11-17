<?php

declare(strict_types=1);

use App\Enums\Billing\UsageStatus;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\UsageRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Tests\Support\Billing;
use Tests\Support\Suggestions;

/*
 * Two "Describe a change" requests race on one balance that covers one hold
 * (spec 8, money rules M-10 and M-19). Rows are committed (DatabaseMigrations)
 * so both processes see them. Run serially: never with --parallel.
 */

it('lets exactly one of two parallel suggestions hold when the balance covers one', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['takes.ai.enabled' => true]);

    $customer = Customer::factory()->withRole()->create();
    $first = Suggestions::baseTake($customer);
    $second = Suggestions::baseTake($customer, ['project_id' => $first->project_id]);
    $hold = Suggestions::expectedEstimate($first, Suggestions::request()->instruction)->maxCharge->toInt();
    Billing::fund($customer, $hold);

    $connection = config('database.connections.'.config('database.default'));
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => (string) config('database.default'),
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'AI_SUGGESTIONS_ENABLED' => 'true',
    ];
    // Time for both processes to boot before the start line.
    $startAt = (string) (microtime(true) + 3.0);

    $results = Process::pool(function (Pool $pool) use ($customer, $first, $second, $env, $startAt): void {
        foreach ([$first, $second] as $index => $take) {
            $pool->as((string) $index)
                ->env($env)
                ->timeout(60)
                ->command([PHP_BINARY, base_path('tests/Committed/suggestion-worker.php'), (string) $customer->id, $take->id, $startAt, '1500']);
        }
    })->start()->wait();

    $outputs = [];

    foreach (['0', '1'] as $index) {
        expect($results[$index]->exitCode())->not->toBe(3, 'A worker missed the start line: the processes did not race.')
            ->and($results[$index]->successful())->toBeTrue($results[$index]->errorOutput());
        $outputs[] = trim($results[$index]->output());
    }

    sort($outputs);

    expect($outputs)->toBe(['insufficient_credits', 'proposed'])
        ->and(UsageRecord::query()->where('customer_id', $customer->id)->count())->toBe(1)
        ->and(UsageRecord::query()->where('customer_id', $customer->id)->sole()->status)->toBe(UsageStatus::Settled)
        ->and(LedgerEntry::query()->where('customer_id', $customer->id)->where('type', 'charge')->sole()->amount_nanos)->toBe(-Suggestions::EXAMPLE_CHARGE)
        ->and($customer->refresh()->balance_nanos)->toBe($hold - Suggestions::EXAMPLE_CHARGE)
        ->and($customer->held_nanos)->toBe(0);

    Billing::assertCachedColumnsMatch($customer);
    $this->artisan('billing:verify')->assertSuccessful();
})->group('concurrency');
