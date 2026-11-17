<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\Support\QueueDelays;
use Tests\Support\SourceScan;

/*
|--------------------------------------------------------------------------
| Repository rules (spec 0 "Configuration and secrets", spec 1-01 issue 2)
|--------------------------------------------------------------------------
*/

/** Laravel Cloud's managed queue rejects delays above 15 minutes. */
const MAX_QUEUE_DELAY_SECONDS = 900;

/** Config files that hold money values: integer nanodollars only. */
const MONEY_CONFIG_PATHS = [
    'config/pixelforge.php',
    'config/ai-pricing.php',
    'config/takes.php',
];

test('.env.example documents every env() key read in config/', function (): void {
    $documented = [];

    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        // Commented keys ("# KEY=") count: they document an optional override.
        if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)=/', $line, $matches) === 1) {
            $documented[] = $matches[1];
        }
    }

    $missing = array_values(array_diff(SourceScan::envKeys(['config']), $documented));

    expect($missing)->toBe([]);
});

test('money config files never use floats', function (): void {
    expect(SourceScan::floatViolations(MONEY_CONFIG_PATHS))->toBe([]);
});

test('no queued class backs off or is delayed longer than 15 minutes', function (): void {
    $violations = [];

    // Jobs, queued listeners, notifications and mailables: anything in app/
    // that implements ShouldQueue.
    foreach (SourceScan::phpFiles(['app']) as $file) {
        $class = 'App\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            substr($file, strlen(base_path('app/'))),
        );

        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $seconds = QueueDelays::declaredSeconds($class);

        if ($seconds !== [] && max($seconds) > MAX_QUEUE_DELAY_SECONDS) {
            $violations[] = "{$class}: ".max($seconds).'s';
        }
    }

    // ->delay(...) / ->release(...) calls: integer literal or class constant
    // within the cap; anything that cannot be verified statically fails.
    $violations = [
        ...$violations,
        ...SourceScan::queueDelayViolations(['app', 'routes'], MAX_QUEUE_DELAY_SECONDS),
    ];

    expect($violations)->toBe([]);
});
