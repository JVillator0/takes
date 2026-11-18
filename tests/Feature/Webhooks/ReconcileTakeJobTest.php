<?php

declare(strict_types=1);

use App\Actions\Takes\ApplyTakeSnapshotAction;
use App\Actions\Takes\FailTakeAction;
use App\Actions\Takes\ReconcileTakeAction;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\ReconcileStatus;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeStatus;
use App\Events\TakeUpdated;
use App\Exceptions\Billing\TransactionNotAllowedException;
use App\Jobs\ReconcileTakeJob;
use App\Models\LedgerEntry;
use App\Models\Take;
use App\Support\Takes\ReconcileStopMarker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixture;
use Tests\Support\Takes;

/*
| ReconcileTakeJob (spec 5, W-15, M-16, M-17): the polling safety net. Takes
| in pool worktrees finish this way, since only main receives webhooks.
*/

beforeEach(function (): void {
    config([
        'pixelforge.base_url' => 'https://pixelforge.test/v1',
        'pixelforge.api_key' => 'pf_test_reconcile',
    ]);
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake();
    Event::fake([TakeUpdated::class]);
});

/** The take's generation as GET /v1/generations/{id} returns it, at $status. */
function fakeGeneration(Take $take, string $status, array $overrides = []): void
{
    $fixture = $status === 'processing' ? 'queued' : $status;

    Http::fake(['pixelforge.test/v1/generations/*' => function (Request $request) use ($take, $status, $fixture, $overrides) {
        // M-16: the provider is called with no transaction open.
        expect(DB::transactionLevel())->toBe(baseTransactionLevel());

        return Http::response(PixelforgeFixture::generation($fixture, [
            'id' => $take->pixelforge_id,
            'status' => $status,
            'metadata' => ['app_take_id' => $take->id],
            'started_at' => $status === 'queued' ? null : '2026-11-04T14:03:13Z',
            ...$overrides,
        ]));
    }]);
}

/** RefreshDatabase wraps each test in one transaction; "none open" means that level. */
function baseTransactionLevel(): int
{
    return 1;
}

function runReconcile(Take $take, int $attempt = 0): void
{
    // A fresh queue fake per poll, so assertions see only what this poll scheduled.
    Queue::fake();
    Cache::flush();
    app()->call([new ReconcileTakeJob($take->id, $attempt), 'handle']);
}

it('settles a take whose webhooks never arrived and stops there', function (): void {
    $take = Takes::in('processing');
    fakeGeneration($take, 'succeeded');

    runReconcile($take);

    expect($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and(Takes::usage($take)->charge_nanos)->toBe(Takes::DOCS_CHARGE)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(1);
    Queue::assertNotPushed(ReconcileTakeJob::class);
    Http::assertSentCount(1);
});

it('voids a take that failed at Pixelforge', function (): void {
    $take = Takes::in('queued');
    fakeGeneration($take, 'failed');

    runReconcile($take);

    expect($take->refresh()->status)->toBe(TakeStatus::Failed)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided);
    Queue::assertNotPushed(ReconcileTakeJob::class);
});

it('schedules the next poll with the next delay while the take is in flight', function (int $attempt, int $delay): void {
    $take = Takes::in('queued');
    fakeGeneration($take, 'processing');

    runReconcile($take, $attempt);

    expect($take->refresh()->status)->toBe(TakeStatus::Processing);
    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id
        && $job->attempt === $attempt + 1
        && $job->delay === $delay);
})->with([[0, 120], [1, 300], [2, 600], [3, 840]]);

it('flags reconcile_exhausted after the last poll and stops', function (): void {
    $take = Takes::in('processing');
    fakeGeneration($take, 'processing');

    runReconcile($take, count(ReconcileTakeJob::DELAYS) - 1);

    expect($take->refresh()->attention_reason)->toBe(AttentionReason::ReconcileExhausted)
        ->and($take->status)->toBe(TakeStatus::Processing);
    Queue::assertNotPushed(ReconcileTakeJob::class);
});

it('keeps every delay of the chain at or under 840 s, about 30 minutes in all', function (): void {
    expect(max(ReconcileTakeJob::DELAYS))->toBeLessThanOrEqual(840)
        ->and(ReconcileTakeJob::MAX_DELAY_SECONDS)->toBeLessThanOrEqual(900)
        ->and(array_sum(ReconcileTakeJob::DELAYS))->toBeGreaterThanOrEqual(27 * 60)->toBeLessThanOrEqual(35 * 60);
});

it('flags reconcile_exhausted and stops when Pixelforge has no such generation', function (): void {
    $take = Takes::in('processing');
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::error('not_found'), 404)]);

    runReconcile($take);

    expect($take->refresh()->attention_reason)->toBe(AttentionReason::ReconcileExhausted)
        ->and($take->status)->toBe(TakeStatus::Processing);
    Queue::assertNotPushed(ReconcileTakeJob::class);
});

it('honours Retry-After on 429, capped at 840 s', function (string $retryAfter, int $delay): void {
    $take = Takes::in('processing');
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::error('rate_limit_exceeded'), 429, ['Retry-After' => $retryAfter])]);

    runReconcile($take);

    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->attempt === 1 && $job->delay === $delay);
})->with([
    'shorter than the schedule' => ['30', 120],
    'longer than the schedule' => ['700', 700],
    'above the queue limit' => ['5000', 840],
]);

it('polls again on the normal schedule when Pixelforge is unavailable', function (): void {
    $take = Takes::in('processing');
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::error('internal_error'), 500)]);

    runReconcile($take);

    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->attempt === 1 && $job->delay === 120);
});

it('fails the take free with a critical alert when the key is revoked', function (): void {
    $take = Takes::in('processing');
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::error('invalid_api_key'), 401)]);

    runReconcile($take);

    expect($take->refresh()->status)->toBe(TakeStatus::Failed)
        ->and($take->failure_code)->toBe(FailureCode::Internal)
        ->and($take->attention_reason)->toBe(AttentionReason::InternalFailure)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided);
    Queue::assertNotPushed(ReconcileTakeJob::class);
});

it('does not poll a take that has no generation yet', function (): void {
    $take = Takes::pendingWithHold();
    Http::fake();

    runReconcile($take);

    Http::assertNothingSent();
    Queue::assertNotPushed(ReconcileTakeJob::class);
});

it('does nothing for a terminal take', function (): void {
    $take = Takes::in('succeeded');
    Http::fake();

    runReconcile($take);

    Http::assertNothingSent();
});

it('fetches the generation of an orphan that arrived without rates and records its absorbed cost (#102)', function (): void {
    $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Ambiguous]);
    app(FailTakeAction::class)->execute($take, FailureCode::SubmitTimeout, 'Submit window expired.');
    $generationId = Takes::generationId($take);
    fakeGeneration($take, 'succeeded', ['id' => $generationId]);

    // What the webhook handler dispatches after ApplyTakeSnapshotAction threw (ReconcileTakeJob::fetchNow).
    Queue::fake();
    app()->call([new ReconcileTakeJob($take->id, 0, false, $generationId), 'handle']);

    $usage = Takes::usage($take);

    expect($take->refresh()->status)->toBe(TakeStatus::Failed)
        ->and($take->attention_reason)->toBe(AttentionReason::OrphanSuccess)
        ->and($usage->status)->toBe(UsageStatus::Voided)
        ->and($usage->absorbed_cost_nanos)->toBe(305_510_000)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(0);
    Http::assertSentCount(1);
});

it('does not fetch a failed take whose orphan cost is already recorded', function (): void {
    $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Ambiguous]);
    app(FailTakeAction::class)->execute($take, FailureCode::SubmitTimeout, 'Submit window expired.');
    app(ApplyTakeSnapshotAction::class)->execute($take, Takes::snapshot($take, 'succeeded'));
    Http::fake();

    runReconcile($take->refresh());

    Http::assertNothingSent();
});

it('records the orphan without its cost when the fetched generation has no rates either', function (): void {
    Log::spy();
    $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Ambiguous]);
    app(FailTakeAction::class)->execute($take, FailureCode::SubmitTimeout, 'Submit window expired.');
    $generationId = Takes::generationId($take);
    fakeGeneration($take, 'succeeded', ['id' => $generationId, 'rates' => null]);

    $result = app(ReconcileTakeAction::class)->execute($take, $generationId);
    $usage = Takes::usage($take);

    expect($result->status)->toBe(ReconcileStatus::Terminal)
        ->and($take->refresh()->status)->toBe(TakeStatus::Failed)
        ->and($take->failure_code)->toBe(FailureCode::SubmitTimeout)
        ->and($take->pixelforge_id)->toBe($generationId)
        ->and($take->attention_reason)->toBe(AttentionReason::OrphanSuccess)
        ->and($usage->status)->toBe(UsageStatus::Voided)
        ->and($usage->flags)->toBe(['orphan'])
        ->and($usage->absorbed_cost_nanos)->toBeNull()
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(0)
        ->and(ReconcileStopMarker::stopped([$take->id]))->toBe([]);
    Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'orphan success without its cost'))->once();
});

it('never re-polls a failed take that may hide a generation without a webhook asking', function (Closure $poll): void {
    // internal after acceptance: it keeps its pixelforge_id, and no orphan cost is recorded.
    $take = Takes::in('processing');
    app(FailTakeAction::class)->execute($take, FailureCode::Internal, 'Revoked key while reconciling.');
    Http::fake();

    $poll($take->refresh());

    Http::assertNothingSent();
    expect($take->refresh()->attention_reason)->toBe(AttentionReason::InternalFailure);
})->with([
    'chain poll' => [fn (Take $take) => runReconcile($take)],
    'reconcile now' => [fn (Take $take) => app(ReconcileTakeAction::class)->execute($take)],
]);

it('keeps a failed take\'s failure reason when the orphan fetch is rejected', function (int $status, string $code): void {
    $take = Takes::in('processing');
    app(FailTakeAction::class)->execute($take, FailureCode::Internal, 'Revoked key while reconciling.');
    $take->refresh();
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::error($code), $status)]);

    $result = app(ReconcileTakeAction::class)->execute($take, (string) $take->pixelforge_id);

    expect($result->status)->toBe(ReconcileStatus::Terminal)
        ->and($take->refresh()->status)->toBe(TakeStatus::Failed)
        ->and($take->failure_code)->toBe(FailureCode::Internal)
        ->and($take->attention_reason)->toBe(AttentionReason::InternalFailure)
        ->and(ReconcileStopMarker::stopped([$take->id]))->toBe([]);
    Http::assertSentCount(1);
})->with([
    '404' => [404, 'not_found'],
    '401' => [401, 'invalid_api_key'],
]);

it('does nothing without an API key', function (): void {
    config(['pixelforge.api_key' => '']);
    $take = Takes::in('processing');
    Http::fake();

    runReconcile($take);

    Http::assertNothingSent();
    Queue::assertNotPushed(ReconcileTakeJob::class);
});

it('keeps one queued poll per take', function (): void {
    $take = Takes::in('processing');
    Queue::fake();
    Cache::flush();

    ReconcileTakeJob::dispatch($take->id)->delay(60);
    ReconcileTakeJob::dispatch($take->id);
    // A one-off fetch has its own key: a queued chain poll does not swallow it.
    ReconcileTakeJob::fetchNow($take->id);

    Queue::assertPushed(ReconcileTakeJob::class, 2);
});

it('starts a chain with the first delay after commit', function (): void {
    $take = Takes::in('processing');
    Queue::fake();
    Cache::flush();

    DB::transaction(fn () => ReconcileTakeJob::start($take->id));

    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->attempt === 0 && $job->delay === 60);
});

it('reconciles one take now from the command, without scheduling a poll', function (): void {
    $take = Takes::in('processing');
    fakeGeneration($take, 'succeeded');
    Queue::fake();

    $this->artisan('takes:reconcile', ['--take' => $take->id])
        ->expectsOutputToContain('terminal, now succeeded')
        ->assertSuccessful();

    expect($take->refresh()->status)->toBe(TakeStatus::Succeeded);
    Queue::assertNotPushed(ReconcileTakeJob::class);
});

it('flags a fetched generation without rates and stops, charging nothing', function (): void {
    Log::spy();
    $take = Takes::in('processing');
    fakeGeneration($take, 'succeeded', ['rates' => null]);

    runReconcile($take);

    expect($take->refresh()->status)->toBe(TakeStatus::Processing)
        ->and($take->attention_reason)->toBe(AttentionReason::InvalidSnapshot)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(0)
        ->and(ReconcileStopMarker::stopped([$take->id]))->toBe([$take->id]);
    Queue::assertNotPushed(ReconcileTakeJob::class);
    Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'no rates'))->once();
});

it('marks a 404 as a definitive stop', function (): void {
    $take = Takes::in('processing');
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::error('not_found'), 404)]);

    runReconcile($take);

    expect(ReconcileStopMarker::stopped([$take->id]))->toBe([$take->id]);
});

it('keeps polling after a rejection that is not a 404', function (): void {
    $take = Takes::in('processing');
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::error('daily_limit_exceeded'), 429)]);

    runReconcile($take);

    expect($take->refresh()->attention_reason)->toBeNull()
        ->and(ReconcileStopMarker::stopped([$take->id]))->toBe([]);
    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->attempt === 1 && $job->delay === 120);
});

it('clears a definitive stop once a poll applies a snapshot again', function (): void {
    $take = Takes::in('processing');
    fakeGeneration($take, 'processing');
    Cache::flush();
    ReconcileStopMarker::mark($take->id, ReconcileStatus::NotFound);

    app()->call([new ReconcileTakeJob($take->id), 'handle']);

    expect(ReconcileStopMarker::stopped([$take->id]))->toBe([]);
});

it('refuses to poll Pixelforge inside a transaction (M-16)', function (): void {
    $take = Takes::in('processing');
    Http::fake();

    expect(fn () => DB::transaction(fn () => app(ReconcileTakeAction::class)->execute($take)))
        ->toThrow(TransactionNotAllowedException::class);
    Http::assertNothingSent();
});
