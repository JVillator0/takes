<?php

declare(strict_types=1);

use App\Enums\Auth\DemoAccount;
use App\Models\Customer;
use Database\Seeders\DemoAccountsSeeder;

/*
| D-072: the showcase customer is read-only (`restricted`); spec 9's smoke
| test runs `takes:showcase --unlock`, creates the history, then `--lock`.
*/

function showcaseRoles(): array
{
    return Customer::query()->where('email', DemoAccount::Showcase->email())->sole()->getRoleNames()->all();
}

it('unlocks and locks the showcase customer', function (): void {
    $this->seed(DemoAccountsSeeder::class);
    expect(showcaseRoles())->toBe(['restricted']);

    $this->artisan('takes:showcase', ['--unlock' => true])
        ->expectsOutput('Showcase customer is now customer.')
        ->assertSuccessful();
    expect(showcaseRoles())->toBe(['customer']);

    $this->artisan('takes:showcase', ['--lock' => true])
        ->expectsOutput('Showcase customer is now restricted.')
        ->assertSuccessful();
    expect(showcaseRoles())->toBe(['restricted']);
});

it('requires exactly one of --unlock or --lock', function (array $options): void {
    $this->seed(DemoAccountsSeeder::class);

    $this->artisan('takes:showcase', $options)
        ->expectsOutput('Pass exactly one of --unlock or --lock.')
        ->assertExitCode(2);
    expect(showcaseRoles())->toBe(['restricted']);
})->with([
    'neither' => [[]],
    'both' => [['--unlock' => true, '--lock' => true]],
]);

it('fails when the showcase customer was never seeded', function (): void {
    $this->artisan('takes:showcase', ['--unlock' => true])->assertFailed();
});
