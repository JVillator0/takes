<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
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

test('no queued job backs off or is delayed longer than 15 minutes', function (): void {
    $violations = [];

    foreach (SourceScan::phpFiles(['app/Jobs']) as $file) {
        $class = 'App\\Jobs\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            substr($file, strlen(base_path('app/Jobs/'))),
        );

        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            continue;
        }

        $job = $reflection->newInstanceWithoutConstructor();

        $backoff = method_exists($job, 'backoff')
            ? $job->backoff()
            : ($reflection->hasProperty('backoff') ? $reflection->getProperty('backoff')->getValue($job) : null);

        $delays = array_merge(
            array_map(intval(...), array_filter(is_array($backoff) ? $backoff : [$backoff], is_numeric(...))),
            is_int($job->delay ?? null) ? [$job->delay] : [],
        );

        if ($delays !== [] && max($delays) > MAX_QUEUE_DELAY_SECONDS) {
            $violations[] = "{$class}: ".max($delays).'s';
        }
    }

    // Literal release()/delay() arguments anywhere a job could be dispatched from.
    foreach (SourceScan::phpFiles(['app']) as $file) {
        $contents = (string) file_get_contents($file);

        if (preg_match_all('/->(?:release|delay)\(\s*(\d+)\s*\)/', $contents, $matches) > 0) {
            foreach ($matches[1] as $seconds) {
                if ((int) $seconds > MAX_QUEUE_DELAY_SECONDS) {
                    $violations[] = str_replace(base_path().'/', '', $file).": {$seconds}s";
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
