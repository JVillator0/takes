<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Models\User;
use Database\Seeders\AdminRolesSeeder;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

/*
 * D-086: the console always keeps a super admin. Two super admins each take the role from the other at the same
 * time, in two real processes: LockSuperAdminsAction locks the `super_admin` role row and re-reads the members
 * after the lock, so exactly one demote applies and the other is refused. Rows are committed
 * (DatabaseMigrations). Serial only.
 */

it('keeps one super admin when two super admins demote each other at once', function (): void {
    $this->seed(AdminRolesSeeder::class);
    $alice = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $bob = User::factory()->admin(AdminRole::SuperAdmin)->create();

    $connection = config('database.connections.'.config('database.default'));
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => (string) config('database.default'),
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'CACHE_STORE' => 'array',
        'BROADCAST_CONNECTION' => 'null',
    ];
    // The full app boots slowly; give both processes time to reach the line.
    $startAt = (string) (microtime(true) + 10.0);

    $results = Process::pool(function (Pool $pool) use ($alice, $bob, $env, $startAt): void {
        foreach (['alice' => [$alice, $bob], 'bob' => [$bob, $alice]] as $side => [$actor, $target]) {
            $pool->as($side)
                ->env($env)
                ->timeout(60)
                ->command([PHP_BINARY, base_path('tests/Committed/admin-demote-worker.php'), (string) $actor->id, (string) $target->id, $startAt]);
        }
    })->start()->wait();

    $outputs = [];
    foreach (['alice', 'bob'] as $side) {
        expect($results[$side]->exitCode())->not->toBe(3, 'A worker missed the start line: the processes did not race.')
            ->and($results[$side]->successful())->toBeTrue($results[$side]->output().$results[$side]->errorOutput());
        $outputs[] = trim($results[$side]->output());
    }

    sort($outputs);
    expect($outputs)->toBe(['ok', 'refused'])
        ->and(User::query()->role(AdminRole::SuperAdmin->value, AdminRole::GUARD)->count())->toBe(1);
})->group('concurrency');
