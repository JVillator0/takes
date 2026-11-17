<?php

declare(strict_types=1);

use App\Models\Suggestion;
use App\Models\Take;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Tests\Support\Billing;
use Tests\Support\Suggestions;

/*
 * money.md M-10: a remix of take P (parent-take lock, then the customer) and
 * Describe a change on P (customer, then the suggestion insert's FK
 * key-share on P) run at once for one customer. The parent is locked FOR KEY
 * SHARE, compatible with the FK's key-share, so there is no lock cycle
 * (no 40P01): both finish. Rows are committed (DatabaseMigrations). Serial only.
 */

it('lets a remix and a suggestion on the same take run at once without a deadlock', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $customer = Suggestions::customer(10_000_000_000);
    $parent = Suggestions::baseTake($customer);

    $connection = config('database.connections.'.config('database.default'));
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => (string) config('database.default'),
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'PIXELFORGE_API_KEY' => 'pf_test_key',
        'ANTHROPIC_API_KEY' => 'sk-ant-test',
        'AI_SUGGESTIONS_ENABLED' => 'true',
        'CACHE_STORE' => 'array',
        'BROADCAST_CONNECTION' => 'null',
    ];
    // The full app boots slowly; give both processes time to reach the line.
    $startAt = (string) (microtime(true) + 10.0);

    $results = Process::pool(function (Pool $pool) use ($parent, $env, $startAt): void {
        foreach (['remix', 'suggest'] as $side) {
            $pool->as($side)
                ->env($env)
                ->timeout(60)
                ->command([PHP_BINARY, base_path('tests/Committed/remix-suggest-worker.php'), $side, $parent->id, $startAt]);
        }
    })->start()->wait();

    foreach (['remix', 'suggest'] as $side) {
        expect($results[$side]->exitCode())->not->toBe(3, 'A worker missed the start line: the processes did not race.')
            ->and($results[$side]->output().$results[$side]->errorOutput())->not->toContain('40P01')
            ->and($results[$side]->successful())->toBeTrue($results[$side]->output().$results[$side]->errorOutput());
    }

    expect(Take::query()->where('parent_take_id', $parent->id)->count())->toBe(1)
        ->and(Suggestion::query()->where('base_take_id', $parent->id)->count())->toBe(1);

    Billing::assertCachedColumnsMatch($customer);
})->group('concurrency');
