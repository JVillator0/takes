<?php

declare(strict_types=1);

use App\Actions\Takes\ApplyTakeSnapshotAction;
use App\Actions\Takes\FailTakeAction;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeStatus;
use App\Enums\Takes\TransitionOutcome;
use App\Enums\Webhooks\WebhookOutcome;
use App\Events\TakeUpdated;
use App\Models\LedgerEntry;
use App\Models\Take;
use App\Models\WebhookDelivery;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Billing;
use Tests\Support\PixelforgeFixture;
use Tests\Support\PixelforgeWebhook;
use Tests\Support\Takes;

/*
| The spec 5 outcome matrix through the endpoint (W-12, M-13, M-14). The
| action itself is covered row by row in tests/Feature/Takes; this proves the
| webhook path stores the same outcome and never moves a take backwards.
*/

beforeEach(function (): void {
    PixelforgeWebhook::configure();
    Queue::fake();
    Event::fake([TakeUpdated::class]);
});

/**
 * A take in $state: a provider status, `failed:<code>` for a local failure
 * after Pixelforge accepted it, or `failed:<code>:<submit kind>:<attempts>`
 * for one before any acceptance, with that `last_submit_error_kind`
 * (`none` = null) and `submit_attempts`.
 */
function matrixTake(string $state): Take
{
    if (! str_starts_with($state, 'failed:')) {
        return Takes::in($state);
    }

    [, $code, $kind, $attempts] = [...explode(':', $state), null, null];
    $code = FailureCode::from($code);
    $take = Takes::pendingWithHold();
    // A cancel is only allowed while waiting for capacity, where nothing exists
    // at Pixelforge (D-044); the others failed after Pixelforge accepted it,
    // unless the row names the submit state the take failed in.
    $take->forceFill(match (true) {
        $kind !== null => ['last_submit_error_kind' => SubmitErrorKind::tryFrom($kind), 'submit_attempts' => (int) $attempts],
        $code === FailureCode::Cancelled => ['last_submit_error_kind' => SubmitErrorKind::Capacity],
        default => ['pixelforge_id' => 'gen_'.$take->id],
    })->save();
    app(FailTakeAction::class)->execute($take, $code, 'local failure');

    return $take->refresh();
}

it('stores the matrix outcome for every row', function (string $from, string $incoming, WebhookOutcome $outcome, string $final): void {
    $take = matrixTake($from);

    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, $incoming))
        ->assertOk()
        ->assertExactJson(['status' => $outcome->value]);

    expect(WebhookDelivery::query()->sole()->outcome)->toBe($outcome)
        ->and($take->refresh()->status->value)->toBe($final);
})->with([
    // non-terminal → later non-terminal / terminal: applied
    ['queued', 'processing', WebhookOutcome::Applied, 'processing'],
    ['queued', 'succeeded', WebhookOutcome::Applied, 'succeeded'],
    ['processing', 'succeeded', WebhookOutcome::Applied, 'succeeded'],
    ['processing', 'failed', WebhookOutcome::Applied, 'failed'],
    // non-terminal → same or earlier
    ['processing', 'processing', WebhookOutcome::IgnoredSameState, 'processing'],
    // succeeded → anything
    ['succeeded', 'succeeded', WebhookOutcome::IgnoredSameState, 'succeeded'],
    ['succeeded', 'processing', WebhookOutcome::IgnoredStale, 'succeeded'],
    ['succeeded', 'failed', WebhookOutcome::IgnoredConflict, 'succeeded'],
    // failed → failed / succeeded
    ['failed', 'failed', WebhookOutcome::IgnoredSameState, 'failed'],
    ['failed:submit_timeout', 'succeeded', WebhookOutcome::OrphanSuccess, 'failed'],
    ['failed:provider_busy', 'succeeded', WebhookOutcome::IgnoredConflict, 'failed'],
    ['failed:cancelled', 'succeeded', WebhookOutcome::IgnoredConflict, 'failed'],
    // D-077(c): an `internal` failure of a take Pixelforge had accepted.
    ['failed:internal', 'succeeded', WebhookOutcome::OrphanSuccess, 'failed'],
    // D-082: an `internal` failure while a submit was in doubt (ambiguous, or an attempt
    // counted but never answered); never after capacity answers only or before any attempt.
    ['failed:internal:ambiguous:2', 'succeeded', WebhookOutcome::OrphanSuccess, 'failed'],
    ['failed:internal:none:1', 'succeeded', WebhookOutcome::OrphanSuccess, 'failed'],
    ['failed:internal:capacity:3', 'succeeded', WebhookOutcome::IgnoredConflict, 'failed'],
    ['failed:internal:none:0', 'succeeded', WebhookOutcome::IgnoredConflict, 'failed'],
    ['failed', 'succeeded', WebhookOutcome::IgnoredConflict, 'failed'],
]);

it('ignores a stale queued snapshot for a processing take', function (): void {
    // Pixelforge sends no generation.queued webhook: a queued snapshot only
    // comes from the create response or a reconcile poll (W-12).
    config(['pixelforge.base_url' => 'https://pixelforge.test/v1', 'pixelforge.api_key' => 'pf_test_matrix']);
    Http::preventStrayRequests();
    $take = Takes::in('processing');
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::generation('queued', [
        'id' => $take->pixelforge_id,
        'metadata' => ['app_take_id' => $take->id],
    ]))]);

    $outcome = app(ApplyTakeSnapshotAction::class)->execute($take, app(PixelforgeClient::class)->getGeneration((string) $take->pixelforge_id));

    expect($outcome)->toBe(TransitionOutcome::IgnoredStale)
        ->and($take->refresh()->status)->toBe(TakeStatus::Processing);

    // And as a webhook type it is not one Pixelforge documents.
    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'queued'))->assertExactJson(['status' => 'ignored_type']);
});

it('keeps a succeeded take and its charge when processing arrives late', function (): void {
    $take = Takes::in('processing');

    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'succeeded'))->assertExactJson(['status' => 'applied']);
    $balance = $take->customer()->firstOrFail()->balance_nanos;

    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'processing'))->assertExactJson(['status' => 'ignored_stale']);

    expect($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and(Takes::usage($take)->charge_nanos)->toBe(Takes::DOCS_CHARGE)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(1)
        ->and($take->customer()->firstOrFail()->balance_nanos)->toBe($balance);
});

it('charges nothing and releases the hold on a failure', function (): void {
    $take = Takes::in('processing');

    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'failed'))->assertExactJson(['status' => 'applied']);

    $customer = $take->customer()->firstOrFail();
    expect($take->refresh()->status)->toBe(TakeStatus::Failed)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided)
        ->and(Takes::usage($take)->charge_nanos)->toBe(0)
        ->and($customer->held_nanos)->toBe(0)
        ->and($customer->balance_nanos)->toBe(Takes::FUNDED_NANOS)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(0);
    Billing::assertCachedColumnsMatch($customer);
});

it('leaves a late success after submit_timeout voided and flagged orphan_success', function (): void {
    $take = matrixTake('failed:submit_timeout');

    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'succeeded'))->assertExactJson(['status' => 'orphan_success']);

    expect($take->refresh()->attention_reason)->toBe(AttentionReason::OrphanSuccess)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(0);
});

it('flags a terminal conflict with an attention reason', function (): void {
    $take = Takes::in('succeeded');

    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'failed'))->assertExactJson(['status' => 'ignored_conflict']);

    expect($take->refresh()->attention_reason)->toBe(AttentionReason::InternalFailure)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Settled);
});
