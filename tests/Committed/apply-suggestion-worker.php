<?php

declare(strict_types=1);

/*
 * Applies one proposed suggestion in its own PHP process, for
 * ApplySuggestionConcurrencyTest (money.md M-18, issue #76). Same shape as
 * apply-worker.php: boot against the test database from the environment,
 * wait for a shared start time, run inside a transaction and keep it (and
 * its locks) open for `holdMs` so the other worker has to wait.
 *
 *   php tests/Committed/apply-suggestion-worker.php <suggestionId> <startAt> <holdMs>
 *
 * Prints the resulting take id; exits 3 when it booted after the start line.
 */

use App\Actions\Suggestions\ApplySuggestionAction;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $suggestionId, $startAt, $holdMs] = $argv;

$suggestion = Suggestion::query()->findOrFail($suggestionId);
$customer = Customer::query()->findOrFail($suggestion->customer_id);
$apply = app(ApplySuggestionAction::class);

// A worker that boots after the start line would run alone, so the race
// would not happen and the test could pass without any lock: report it.
if (microtime(true) >= (float) $startAt) {
    fwrite(STDERR, 'late');
    exit(3);
}

time_sleep_until((float) $startAt);

$take = DB::transaction(function () use ($apply, $customer, $suggestion, $holdMs): Take {
    $take = $apply->execute($customer, $suggestion);

    usleep((int) $holdMs * 1000);

    return $take;
});

echo $take->id;
