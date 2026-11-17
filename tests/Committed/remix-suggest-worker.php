<?php

declare(strict_types=1);

/*
 * One side of RemixSuggestionLockRaceTest (money.md M-10), in its own PHP
 * process. `remix` creates a remix of take P through CreateTakeAction and,
 * right after its parent-take lock, pauses so the other side runs in the
 * middle (parent locked → customer not yet). `suggest` starts a moment later
 * and runs Describe a change on P (CreateSuggestionAction: customer lock,
 * then the suggestion insert, whose FK key-shares P). With a FOR UPDATE on
 * the parent this is a lock cycle (40P01); with FOR KEY SHARE both finish.
 *
 *   php tests/Committed/remix-suggest-worker.php <remix|suggest> <takeId> <startAt>
 *
 * Prints `ok` or the error class; exits 3 when it booted after the start line.
 */

use App\Actions\Suggestions\CreateSuggestionAction;
use App\Actions\Takes\CreateTakeAction;
use App\DTOs\Takes\CreateTakeDTO;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Take;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Suggestions;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $side, $takeId, $startAt] = $argv;

$take = Take::query()->findOrFail($takeId);
$customer = Customer::query()->findOrFail($take->customer_id);
$project = Project::query()->findOrFail($take->project_id);
Queue::fake();

if ($side === 'suggest') {
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));
}

// A worker that boots after the start line would run alone: report it.
if (microtime(true) >= (float) $startAt) {
    fwrite(STDERR, 'late');
    exit(3);
}

time_sleep_until((float) $startAt + ($side === 'suggest' ? 0.4 : 0.0));

try {
    if ($side === 'remix') {
        // Hold the parent-take lock for a while before the customer lock.
        Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (str_contains(strtolower($query->sql), 'for key share') || str_contains(strtolower($query->sql), 'for update')) {
                if (str_contains($query->sql, '"takes"')) {
                    usleep(1_500_000);
                }
            }
        });

        app(CreateTakeAction::class)->execute($customer, $project, CreateTakeDTO::fromArray([
            'prompt' => 'Remix of P, warmer light',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'idempotency_key' => (string) Str::uuid(),
            'parent_take_id' => $take->id,
        ]));
    } else {
        app(CreateSuggestionAction::class)->execute($customer, $take, Suggestions::request());
    }

    echo 'ok';
} catch (Throwable $exception) {
    echo $exception::class.': '.$exception->getMessage();
    exit(1);
}
