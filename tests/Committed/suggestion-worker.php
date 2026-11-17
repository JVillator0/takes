<?php

declare(strict_types=1);

/*
 * One "Describe a change" request in its own PHP process, for the
 * concurrency test (spec 8, money rule M-10). Boots the app against the test
 * database passed in the environment, fakes the model (it answers after
 * `callMs`, so the other worker's T1 runs while this call is in flight),
 * waits for the shared start time and runs CreateSuggestionAction.
 *
 *   php tests/Committed/suggestion-worker.php <customerId> <takeId> <startAt> <callMs>
 *
 * Prints one word: the suggestion status (proposed | failed) or the denial reason.
 * Exits 3 ("late" on stderr) when it boots after the start line.
 */

use App\Actions\Suggestions\CreateSuggestionAction;
use App\Exceptions\Suggestions\SuggestionDeniedException;
use App\Models\Customer;
use App\Models\Take;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Tests\Support\Suggestions;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $customerId, $takeId, $startAt, $callMs] = $argv;

Suggestions::fake(function () use ($callMs) {
    usleep((int) $callMs * 1000);

    return Http::response(Suggestions::messageBody(Suggestions::proposal()));
});

// A worker that boots after the start line would run alone, so the race
// would not happen and the test could pass without the locks: report it.
if (microtime(true) >= (float) $startAt) {
    fwrite(STDERR, 'late');
    exit(3);
}

time_sleep_until((float) $startAt);

try {
    $suggestion = app(CreateSuggestionAction::class)->execute(
        Customer::query()->findOrFail((int) $customerId),
        Take::query()->findOrFail($takeId),
        Suggestions::request(),
    );

    echo $suggestion->status->value;
} catch (SuggestionDeniedException $exception) {
    echo $exception->reason->value;
}
