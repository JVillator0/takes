<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tests\Support\SourceScan;

/*
|--------------------------------------------------------------------------
| Architecture rules (spec 1-01, "PHP rules")
|--------------------------------------------------------------------------
|
| Several target namespaces do not exist yet (Queries, DTOs, Enums, money
| code). Every rule is written so it passes while the namespace is empty and
| bites as soon as code lands there.
|
*/

/** Namespaces that hold money arithmetic: integers only (D-021). */
const MONEY_PATHS = [
    'app/Support/Money',
    'app/Actions/Billing',
    'app/Actions/Takes',
    'app/Actions/Suggestions',
];

arch('the app uses strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('actions are final readonly')
    ->expect('App\Actions')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();

arch('actions only expose execute()')
    ->expect('App\Actions')
    ->classes()
    ->not->toHavePublicMethodsBesides(['__construct', 'execute'])
    // Fortify's contracts dictate the method names (create(), reset()).
    ->ignoring('App\Actions\Fortify');

arch('queries are final and expose execute()')
    ->expect('App\Queries')
    ->classes()
    ->toBeFinal()
    ->toHaveMethod('execute');

arch('DTOs are final readonly')
    ->expect('App\DTOs')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();

arch('enums are backed')
    ->expect('App\Enums')
    ->toBeEnums()
    ->toImplement(BackedEnum::class);

arch('controllers do not query the database or call services directly')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\DatabaseManager',
        'Illuminate\Database\ConnectionInterface',
        'Illuminate\Database\Query\Builder',
        'Illuminate\Database\Eloquent\Builder',
        'App\Services',
    ]);

arch('the Pixelforge client is never used from controllers, models or resources')
    ->expect('App\Services\Pixelforge')
    ->not->toBeUsedIn([
        'App\Http\Controllers',
        'App\Http\Resources',
        'App\Models',
    ]);

arch('Eloquent models live only in App\Models')
    ->expect('App')
    ->classes()
    ->not->toExtend(Model::class)
    ->ignoring('App\Models');

arch('no debugging helpers are left behind')
    ->expect(['dd', 'ddd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

test('env() is only called from config/', function (): void {
    $calls = SourceScan::functionCalls(
        ['app', 'bootstrap', 'database', 'routes', 'resources/views', 'tests'],
        ['env'],
    );

    expect($calls)->toBe([]);
});

test('money code never uses floats', function (): void {
    expect(SourceScan::floatViolations(MONEY_PATHS))->toBe([]);
});
