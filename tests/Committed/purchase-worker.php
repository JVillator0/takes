<?php

declare(strict_types=1);

/*
 * One credit purchase in its own PHP process, for the invoice concurrency
 * tests (spec 6). Boots the app against the test database passed in the
 * environment, waits for a shared start time so every worker races, runs
 * PurchaseCreditsAction inside an outer transaction and keeps it (and its
 * customer and invoice-counter locks) open for `holdMs`.
 *
 *   php tests/Committed/purchase-worker.php <customerId> <packUsd> <idempotencyKey> <startAt> <holdMs> [<nowIso>]
 *
 * Prints the purchase id and the invoice number: "<purchaseId> INV-2026-000001".
 */

use App\Actions\Billing\PurchaseCreditsAction;
use App\Models\Customer;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $customerId, $packUsd, $key, $startAt, $holdMs] = $argv;

if (isset($argv[6])) {
    CarbonImmutable::setTestNow($argv[6]);
}

// A slow boot may pass the start line; time_sleep_until() warns on a past time.
if ((float) $startAt > microtime(true)) {
    time_sleep_until((float) $startAt);
}

$purchase = DB::transaction(function () use ($customerId, $packUsd, $key, $holdMs): Purchase {
    $customer = Customer::query()->findOrFail((int) $customerId);
    $purchase = app(PurchaseCreditsAction::class)->execute($customer, (int) $packUsd, $key);

    usleep((int) $holdMs * 1000);

    return $purchase;
});

echo $purchase->id.' '.$purchase->invoice?->number;
