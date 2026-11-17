<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
| config/takes.php (spec 3-01, D-066): USD env strings become integer
| nanodollars through Nanodollars::fromDecimalString, never a float.
*/

const TAKES_ENV_KEYS = [
    'TAKES_SUPPORT_EMAIL',
    'TAKES_DAILY_CAP_PER_CUSTOMER',
    'TAKES_DAILY_CAP_GLOBAL',
    'WELCOME_CREDIT_USD',
    'AI_SUGGESTIONS_ENABLED',
    'AI_DAILY_BUDGET_USD',
    'AI_DAILY_LIMIT_PER_CUSTOMER',
    'TAKES_AI_THROTTLE_PER_MINUTE',
    'DEMO_LOGIN_ENABLED',
    'DEMO_LOGIN_THROTTLE_PER_IP',
    'DEMO_ACCOUNTS_PASSWORD',
];

/**
 * Loads config/takes.php in a fresh PHP process with exactly the given env
 * values: every other takes key is unset, so nothing leaks from the test
 * process (and env is never touched outside config/, arch rule).
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function loadTakesConfig(array $env = []): array
{
    $root = dirname(__DIR__, 3);
    $variables = [...array_fill_keys(TAKES_ENV_KEYS, false), ...$env];

    $process = new Process(
        [PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo serialize(require "config/takes.php");'],
        $root,
        $variables,
    );
    $process->run();

    if (! $process->isSuccessful()) {
        throw new InvalidArgumentException(trim($process->getErrorOutput().$process->getOutput()));
    }

    $config = unserialize($process->getOutput());

    return is_array($config) ? $config : throw new UnexpectedValueException('config/takes.php must return an array.');
}

it('loads the spec 3-01 defaults', function (): void {
    expect(loadTakesConfig())->toBe([
        'support_email' => 'support@example.com',
        'daily_cap_per_customer' => 40,
        'daily_cap_global' => 300,
        'welcome_credit_nanos' => 1_000_000_000,
        'ai' => [
            'enabled' => true,
            'daily_budget_nanos' => 1_000_000_000,
            'daily_limit_per_customer' => 30,
            'throttle_per_minute' => 6,
        ],
        'purchase_packs_usd' => [5, 10, 25],
        'demo' => [
            'enabled' => false,
            'throttle_per_ip_per_hour' => 5,
            'ttl_hours' => 24,
            'password' => 'password',
        ],
        // Spec 3-03 (PurchaseCreditsAction).
        'purchase_packs_usd' => [5, 10, 25],
    ]);
});

it('applies env overrides', function (): void {
    $config = loadTakesConfig([
        'TAKES_SUPPORT_EMAIL' => 'help@takes.test',
        'TAKES_DAILY_CAP_PER_CUSTOMER' => '10',
        'TAKES_DAILY_CAP_GLOBAL' => '50',
        'AI_SUGGESTIONS_ENABLED' => 'false',
        'AI_DAILY_LIMIT_PER_CUSTOMER' => '3',
        'TAKES_AI_THROTTLE_PER_MINUTE' => '2',
        'DEMO_LOGIN_ENABLED' => 'true',
        'DEMO_LOGIN_THROTTLE_PER_IP' => '9',
    ]);

    expect($config['support_email'])->toBe('help@takes.test')
        ->and($config['daily_cap_per_customer'])->toBe(10)
        ->and($config['daily_cap_global'])->toBe(50)
        ->and($config['ai']['enabled'])->toBeFalse()
        ->and($config['ai']['daily_limit_per_customer'])->toBe(3)
        ->and($config['ai']['throttle_per_minute'])->toBe(2)
        ->and($config['demo']['enabled'])->toBeTrue()
        ->and($config['demo']['throttle_per_ip_per_hour'])->toBe(9);
});

it('parses USD env strings into integer nanodollars', function (string $usd, int $nanos): void {
    $config = loadTakesConfig(['AI_DAILY_BUDGET_USD' => $usd, 'WELCOME_CREDIT_USD' => $usd]);

    expect($config['ai']['daily_budget_nanos'])->toBe($nanos)
        ->and($config['welcome_credit_nanos'])->toBe($nanos);
})->with([
    ['0.5', 500_000_000],
    ['2', 2_000_000_000],
    ['0.000000001', 1],
    ['10.25', 10_250_000_000],
]);

it('rejects a USD value that is not a plain decimal string', function (): void {
    loadTakesConfig(['AI_DAILY_BUDGET_USD' => '1e3']);
})->throws(InvalidArgumentException::class);

it('never holds a float', function (): void {
    $config = loadTakesConfig(['AI_DAILY_BUDGET_USD' => '0.5']);

    array_walk_recursive($config, function (mixed $value): void {
        expect($value)->not->toBeFloat();
    });
});
