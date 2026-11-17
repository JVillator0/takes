<?php

declare(strict_types=1);

/*
 * One money operation in its own PHP process, for the concurrency tests
 * (spec 3-03 Tests). Boots the app against the test database passed in the
 * environment, waits for a shared start time so both workers race, runs the
 * action in a transaction and keeps that transaction (and its locks) open for
 * `holdMs` so the other worker must wait on the customer row.
 *
 *   php tests/Committed/worker.php open <takeId> <startAt> <holdMs> <maxCostNanos>
 *   php tests/Committed/worker.php settle <usageId> <startAt> <holdMs>
 *
 * Prints one word: opened | insufficient | settled. Exits 3 ("late" on
 * stderr) when it booted after the start line, so no race happened.
 */

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Billing\SettleUsageAction;
use App\Enums\Billing\Provider;
use App\Exceptions\Billing\InsufficientCreditsException;
use App\Models\Take;
use App\Models\UsageRecord;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Billing;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $id, $startAt, $holdMs] = $argv;
$maxCost = (int) ($argv[5] ?? 0);

// A worker that boots after the start line would run alone, so the race
// would not happen and the test could pass without the lock: report it.
if (microtime(true) >= (float) $startAt) {
    fwrite(STDERR, 'late');
    exit(3);
}

time_sleep_until((float) $startAt);

try {
    DB::transaction(function () use ($operation, $id, $holdMs, $maxCost): void {
        if ($operation === 'open') {
            // Lock order: take first, then the action locks the customer.
            $take = Take::query()->lockForUpdate()->findOrFail($id);
            app(OpenUsageAction::class)->execute($take, Provider::Pixelforge, Billing::estimate($maxCost), Billing::pixelforgeRates());
        } else {
            $usage = UsageRecord::query()->findOrFail($id);
            app(SettleUsageAction::class)->execute($usage, Billing::exampleUnits(), Billing::pixelforgeRates(), CarbonImmutable::now());
        }

        usleep((int) $holdMs * 1000);
    });

    echo $operation === 'open' ? 'opened' : 'settled';
} catch (InsufficientCreditsException) {
    echo 'insufficient';
}
