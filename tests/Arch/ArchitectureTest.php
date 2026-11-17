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

// Class-reference check: it catches the DB facade, the connection, both
// query builders and App\Services in controllers. Eloquent chains on models
// (User::query(), $user->passkeys()->latest()) do not reference these
// classes, so they are a review concern, not part of this rule.
arch('controllers do not reference DB, query builder classes or App\Services')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\DatabaseManager',
        'Illuminate\Database\ConnectionInterface',
        'Illuminate\Database\Query\Builder',
        'Illuminate\Database\Eloquent\Builder',
        'App\Services',
    ]);

// toUse() cannot see root-namespace names, so the global facade alias
// (\DB::table(), registered by Facade::defaultAliases()) needs a token scan.
test('controllers do not use the global DB facade alias', function (): void {
    expect(SourceScan::globalClassReferences(['app/Http/Controllers'], ['DB']))->toBe([]);
});

// Allow-list from spec 1-01. Namespaces that do not exist yet are fine.
arch('the Pixelforge client is only used from actions, queries, jobs, the shared-props middleware and Filament pages/widgets')
    ->expect('App\Services\Pixelforge')
    ->toOnlyBeUsedIn([
        'App\Services\Pixelforge',
        'App\Actions',
        'App\Queries',
        'App\Jobs',
        'App\Http\Middleware\HandleInertiaRequests',
        'App\Filament\Pages',
        'App\Filament\Widgets',
        'App\Filament\Clusters',
    ]);

arch('Eloquent models live only in App\Models')
    ->expect('App')
    ->classes()
    ->not->toExtend(Model::class)
    ->ignoring('App\Models');

arch('no debugging helpers are left behind')
    ->expect(['dd', 'ddd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

const OUTSIDE_CONFIG_PATHS = ['app', 'bootstrap', 'database', 'routes', 'resources/views', 'tests'];

test('environment variables are only read from config/', function (): void {
    $reads = [
        ...SourceScan::functionCalls(OUTSIDE_CONFIG_PATHS, ['env', 'getenv', 'putenv']),
        ...SourceScan::variableReferences(OUTSIDE_CONFIG_PATHS, ['$_ENV', '$_SERVER']),
    ];

    expect($reads)->toBe([]);
});

arch('Illuminate\Support\Env is only used from config/')
    ->expect('Illuminate\Support\Env')
    ->not->toBeUsed();

test('money code never uses floats', function (): void {
    expect(SourceScan::floatViolations(MONEY_PATHS))->toBe([]);
});
