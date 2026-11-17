<?php

declare(strict_types=1);

use App\Enums\Billing\UsageStatus;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Tests\Support\Billing;
use Tests\Support\Suggestions;

/*
 * Two real processes apply the same suggestion at once (money.md M-18,
 * issue #76): one take, one hold. The suggestion lock serializes them and
 * the loser replays the winner's take (uuid v5 key of the suggestion id;
 * unique takes(suggestion_id) as the backstop). Rows are committed
 * (DatabaseMigrations, tests/Pest.php) so the workers see them. Serial only.
 */

it('creates one take and one hold when two processes apply one suggestion in parallel', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $customer = Suggestions::customer(10_000_000_000);
    $base = Suggestions::baseTake($customer);
    $suggestion = Suggestion::factory()->proposed()->create([
        'customer_id' => $customer->id,
        'project_id' => $base->project_id,
        'base_take_id' => $base->id,
        'proposal' => Suggestions::proposal(),
    ]);

    $connection = config('database.connections.'.config('database.default'));
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => (string) config('database.default'),
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        // The take pipeline refuses without a key; nothing calls Pixelforge here
        // (pricing comes from the cache or the fallback inside the transaction).
        'PIXELFORGE_API_KEY' => 'pf_test_key',
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'BROADCAST_CONNECTION' => 'null',
    ];
    // Time for both processes to boot before the start line; the full app
    // (Filament included) boots slower than the bare money workers.
    $startAt = (string) (microtime(true) + 10.0);

    $results = Process::pool(function (Pool $pool) use ($suggestion, $env, $startAt): void {
        foreach (['first', 'second'] as $name) {
            $pool->as($name)
                ->env($env)
                ->timeout(60)
                ->command([PHP_BINARY, base_path('tests/Committed/apply-suggestion-worker.php'), $suggestion->id, $startAt, '500']);
        }
    })->start()->wait();

    $takeIds = [];

    foreach (['first', 'second'] as $name) {
        expect($results[$name]->exitCode())->not->toBe(3, 'A worker missed the start line: the processes did not race.')
            ->and($results[$name]->successful())->toBeTrue($results[$name]->errorOutput());
        $takeIds[] = trim($results[$name]->output());
    }

    $take = Take::query()->where('suggestion_id', $suggestion->id)->sole();
    $usage = UsageRecord::query()->where('billable_id', $take->id)->sole();

    expect(array_unique($takeIds))->toBe([$take->id])
        ->and($usage->status)->toBe(UsageStatus::Open)
        ->and($customer->refresh()->held_nanos)->toBe($usage->max_charge_nanos)
        ->and($suggestion->refresh()->resulting_take_id)->toBe($take->id);

    Billing::assertCachedColumnsMatch($customer);
})->group('concurrency');
