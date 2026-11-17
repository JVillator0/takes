<?php

declare(strict_types=1);

use App\Actions\Takes\ApplyTakeSnapshotAction;
use App\Actions\Takes\CancelTakeAction;
use App\Actions\Takes\ReconcileTakeAction;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeStatus;
use App\Enums\Takes\TransitionOutcome;
use App\Http\Resources\TakeResource;
use App\Jobs\ReconcileTakeJob;
use App\Jobs\SubmitTakeJob;
use App\Models\Take;
use App\Support\Pixelforge\SubmitGate;
use Database\Seeders\RolesAndPermissionsSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\TakeFlow;
use Tests\Support\Takes;

/*
| Spec 4 "Submit": every row of the outcome table (3-04 exceptions), the
| 30-minute window by last outcome (D-025, D-049), the in-flight gate and
| the request budget, release delays ≤ 900 s (M-17), and no transaction
| open during the call (M-16). The take starts the way a real one does:
| pending with its hold open (Takes::pendingWithHold).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    TakeFlow::configure();
    Queue::fake();
    RateLimiter::clear(SubmitTakeJob::RATE_LIMIT_KEY);
});

/** Fakes POST /v1/generations with a sequence of responses. */
function fakeCreate(mixed ...$responses): void
{
    $sequence = Http::sequence();

    foreach ($responses as $response) {
        $response === 'timeout' ? $sequence->pushFailedConnection() : $sequence->pushResponse($response);
    }

    Http::preventStrayRequests();
    Http::fake([TakeFlow::API.'/generations' => $sequence]);
}

function acceptedFor(Take $take, bool $replayed = false): PromiseInterface
{
    return TakeFlow::accepted(['metadata' => ['app_take_id' => $take->id, 'app_customer_id' => (string) $take->customer_id]], $replayed);
}

it('applies a 202: the take is queued, the gate slot is freed and reconcile starts', function (bool $replayed): void {
    $take = Takes::pendingWithHold();
    fakeCreate(acceptedFor($take, $replayed));

    expect(TakeFlow::runSubmit($take))->toBeNull();

    $take->refresh();
    expect($take->status)->toBe(TakeStatus::Queued)
        ->and($take->pixelforge_id)->toBe('gen_'.strtolower($take->id))
        ->and($take->submit_attempts)->toBe(1)
        ->and($take->submitted_at)->not->toBeNull()
        ->and(app(SubmitGate::class)->isSubmitting($take))->toBeFalse()
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $take->idempotency_key)
        && $request['metadata'] === ['app_take_id' => $take->id, 'app_customer_id' => (string) $take->customer_id]
        && $request['prompt'] === $take->prompt);
    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id);
})->with(['202' => false, 'replayed 202' => true]);

it('waits for capacity on a busy answer, honouring Retry-After plus jitter, with the same key', function (int $status, string $code, string $explanation): void {
    $take = Takes::pendingWithHold();
    fakeCreate(TakeFlow::error($code, $status, '7'), acceptedFor($take));

    $delay = TakeFlow::runSubmit($take);

    $take->refresh();
    expect($delay)->toBeGreaterThanOrEqual(7)->toBeLessThanOrEqual(12)
        ->and($take->status)->toBe(TakeStatus::Pending)
        ->and($take->last_submit_error_kind)->toBe(SubmitErrorKind::Capacity)
        ->and($take->last_submit_error)->toBe($code)
        ->and($take->submit_attempts)->toBe(1)
        // Definite non-creation: the gate slot is freed right away (#98).
        ->and(app(SubmitGate::class)->isSubmitting($take))->toBeFalse()
        ->and((new TakeResource($take->load('usageRecord')))->resolve()['explanation'])->toBe(__($explanation));

    expect(TakeFlow::runSubmit($take))->toBeNull()
        ->and($take->refresh()->status)->toBe(TakeStatus::Queued);

    $keys = Http::recorded()->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0]);
    expect($keys->unique()->all())->toBe([$take->idempotency_key]);
})->with([
    '429 concurrency' => [429, 'concurrency_limit_exceeded', 'takes.explanation.waiting_concurrency'],
    '429 rate limit' => [429, 'rate_limit_exceeded', 'takes.explanation.waiting_busy'],
    '503 overloaded' => [503, 'overloaded', 'takes.explanation.waiting_busy'],
]);

it('caps every release at 900 s, even for a long Retry-After', function (): void {
    $take = Takes::pendingWithHold();
    fakeCreate(TakeFlow::error('rate_limit_exceeded', 429, '3600'));

    expect(TakeFlow::runSubmit($take))->toBe(SubmitTakeJob::MAX_DELAY_SECONDS);
});

it('never releases past the end of the 30-minute window', function (): void {
    $take = Takes::pendingWithHold();
    $this->travel(29)->minutes();
    $this->travel(50)->seconds();
    fakeCreate(TakeFlow::error('overloaded', 503, '120'));

    // 10 s left: the next run lands one second after the window, which expires it.
    expect(TakeFlow::runSubmit($take))->toBeLessThanOrEqual(11);
});

it('retries an ambiguous outcome with the same key, keeping the gate slot until it expires', function (mixed $failure): void {
    $take = Takes::pendingWithHold();
    fakeCreate($failure, acceptedFor($take, replayed: true));

    $delay = TakeFlow::runSubmit($take);

    $take->refresh();
    expect($delay)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(10)
        ->and($take->status)->toBe(TakeStatus::Pending)
        ->and($take->last_submit_error_kind)->toBe(SubmitErrorKind::Ambiguous)
        ->and(app(SubmitGate::class)->isSubmitting($take))->toBeTrue();

    // The replay succeeds: one generation, the same key on both attempts.
    TakeFlow::runSubmit($take);

    expect($take->refresh()->status)->toBe(TakeStatus::Queued)
        ->and($take->submit_attempts)->toBe(2)
        ->and(app(SubmitGate::class)->isSubmitting($take))->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $take->idempotency_key));
})->with([
    'timeout' => 'timeout',
    '500' => fn () => TakeFlow::error('internal_error', 500),
    '502' => fn () => Http::response('Bad gateway', 502),
]);

it('keeps ambiguous sticky: a later busy answer never makes the take cancellable', function (): void {
    $take = Takes::pendingWithHold();
    fakeCreate('timeout', TakeFlow::error('concurrency_limit_exceeded', 429, '5'));

    TakeFlow::runSubmit($take);
    TakeFlow::runSubmit($take);

    expect($take->refresh()->last_submit_error_kind)->toBe(SubmitErrorKind::Ambiguous)
        ->and($take->last_submit_error)->toBe('concurrency_limit_exceeded');
});

it('fails a definite rejection now, free', function (int $status, string $code, FailureCode $failure): void {
    $take = Takes::pendingWithHold();
    $customer = $take->customer()->firstOrFail();
    $balance = $customer->balance_nanos;
    fakeCreate(TakeFlow::error($code, $status));

    expect(TakeFlow::runSubmit($take))->toBeNull();

    $take->refresh();
    expect($take->status)->toBe(TakeStatus::Failed)
        ->and($take->failure_code)->toBe($failure)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided)
        ->and(Takes::usage($take)->charge_nanos)->toBe(0)
        ->and($customer->refresh()->held_nanos)->toBe(0)
        ->and($customer->balance_nanos)->toBe($balance)
        ->and(app(SubmitGate::class)->isSubmitting($take))->toBeFalse();
})->with([
    'content policy' => [400, 'content_policy_violation', FailureCode::ContentPolicy],
    'validation' => [422, 'validation_failed', FailureCode::Validation],
    'daily limit' => [429, 'daily_limit_exceeded', FailureCode::DailyLimit],
]);

it('fails 401, 404 and 409 as internal with a critical log and Needs attention', function (int $status, string $code): void {
    Log::spy();
    $take = Takes::pendingWithHold();
    fakeCreate(TakeFlow::error($code, $status));

    TakeFlow::runSubmit($take);

    $take->refresh();
    expect($take->status)->toBe(TakeStatus::Failed)
        ->and($take->failure_code)->toBe(FailureCode::Internal)
        ->and($take->attention_reason)->toBe(AttentionReason::InternalFailure)
        ->and($take->customer()->firstOrFail()->held_nanos)->toBe(0);
    Log::shouldHaveReceived('critical')->atLeast()->once();
})->with([
    '401' => [401, 'invalid_api_key'],
    '404' => [404, 'not_found'],
    '409' => [409, 'idempotency_key_reused'],
]);

it('does not call Pixelforge when 3 generations are in flight', function (): void {
    Take::factory()->queued()->count(3)->create();
    $take = Takes::pendingWithHold();
    fakeCreate(acceptedFor($take));

    $delay = TakeFlow::runSubmit($take);

    expect($delay)->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(8)
        ->and($take->refresh()->submit_attempts)->toBe(0)
        ->and($take->last_submit_error_kind)->toBe(SubmitErrorKind::Capacity)
        ->and($take->last_submit_error)->toBe('concurrency_limit_exceeded');
    Http::assertNothingSent();
});

it('does not call Pixelforge when the app-wide request budget is spent', function (): void {
    foreach (range(1, SubmitTakeJob::REQUESTS_PER_MINUTE) as $hit) {
        RateLimiter::hit(SubmitTakeJob::RATE_LIMIT_KEY, 60);
    }

    $take = Takes::pendingWithHold();
    fakeCreate(acceptedFor($take));

    expect(TakeFlow::runSubmit($take))->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(10)
        ->and($take->refresh()->submit_attempts)->toBe(0)
        ->and($take->last_submit_error_kind)->toBeNull();
    Http::assertNothingSent();
});

it('is a no-op for a take that is no longer pending', function (string $status): void {
    $take = Takes::in($status);
    fakeCreate(acceptedFor($take));

    expect(TakeFlow::runSubmit($take))->toBeNull();
    Http::assertNothingSent();
})->with(['queued', 'succeeded', 'failed']);

it('is a no-op for a cancelled take', function (): void {
    $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Capacity]);
    app(CancelTakeAction::class)->execute($take);
    fakeCreate(acceptedFor($take));

    expect(TakeFlow::runSubmit($take))->toBeNull()
        ->and($take->refresh()->failure_code)->toBe(FailureCode::Cancelled);
    Http::assertNothingSent();
});

it('fails submit_timeout after 30 min of ambiguous outcomes; a later success is an orphan', function (): void {
    $take = Takes::pendingWithHold();
    fakeCreate('timeout');
    TakeFlow::runSubmit($take);

    $this->travel(31)->minutes();
    TakeFlow::runSubmit($take);

    $take->refresh();
    expect($take->status)->toBe(TakeStatus::Failed)
        ->and($take->failure_code)->toBe(FailureCode::SubmitTimeout)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided)
        ->and($take->customer()->firstOrFail()->held_nanos)->toBe(0);

    $outcome = app(ApplyTakeSnapshotAction::class)->execute($take, Takes::snapshot($take, 'succeeded'));

    expect($outcome)->toBe(TransitionOutcome::OrphanSuccess)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided)
        ->and($take->refresh()->status)->toBe(TakeStatus::Failed);
});

it('fails provider_busy after 30 min of capacity answers only; a later success is a conflict', function (): void {
    $take = Takes::pendingWithHold();
    fakeCreate(TakeFlow::error('concurrency_limit_exceeded', 429, '5'));
    TakeFlow::runSubmit($take);

    $this->travel(30)->minutes();
    TakeFlow::runSubmit($take);

    $take->refresh();
    expect($take->failure_code)->toBe(FailureCode::ProviderBusy)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided);

    expect(app(ApplyTakeSnapshotAction::class)->execute($take, Takes::snapshot($take, 'succeeded')))
        ->toBe(TransitionOutcome::IgnoredConflict);
});

it('treats an attempt that never reported back as in doubt when the window ends', function (): void {
    // The worker died during the call: the attempt was counted, no outcome recorded.
    $take = Takes::pendingWithHold(['submit_attempts' => 1]);
    Http::preventStrayRequests();

    $this->travel(31)->minutes();
    (new SubmitTakeJob($take->id))->failed(new MaxAttemptsExceededException('retryUntil passed'));

    expect($take->refresh()->failure_code)->toBe(FailureCode::SubmitTimeout);
});

it('expires the take when the worker gives up after retryUntil', function (): void {
    $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Capacity, 'submit_attempts' => 1]);

    expect((new SubmitTakeJob($take->id))->retryUntil()->getTimestamp())
        ->toBe($take->created_at->addMinutes(30)->getTimestamp());

    $this->travel(31)->minutes();
    (new SubmitTakeJob($take->id))->failed(new MaxAttemptsExceededException('retryUntil passed'));

    expect($take->refresh()->failure_code)->toBe(FailureCode::ProviderBusy)
        ->and($take->customer()->firstOrFail()->held_nanos)->toBe(0);
});

it('fails the take free without calling when the service is not configured', function (): void {
    config(['pixelforge.api_key' => '']);
    $take = Takes::pendingWithHold();
    Http::preventStrayRequests();

    TakeFlow::runSubmit($take);

    expect($take->refresh()->failure_code)->toBe(FailureCode::Internal)
        ->and($take->customer()->firstOrFail()->held_nanos)->toBe(0);
});

it('holds no transaction open and no row locked during the create call', function (): void {
    $take = Takes::pendingWithHold();
    // RefreshDatabase wraps the test in one transaction: that is the baseline.
    $baseline = DB::transactionLevel();
    Http::fake([TakeFlow::API.'/generations' => function (Request $request) use ($baseline, $take) {
        expect(DB::transactionLevel())->toBe($baseline);

        return acceptedFor($take);
    }]);

    TakeFlow::runSubmit($take);

    expect($take->refresh()->status)->toBe(TakeStatus::Queued);
});

it('drops a duplicate dispatch while one is waiting (unique per take)', function (): void {
    $job = new SubmitTakeJob('01jc2z8q4n6v0m3h7x5t9r2b1k');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('01jc2z8q4n6v0m3h7x5t9r2b1k')
        ->and($job->uniqueFor)->toBeGreaterThan(SubmitTakeJob::MAX_DELAY_SECONDS)
        ->and($job->backoff())->each->toBeLessThanOrEqual(SubmitTakeJob::MAX_DELAY_SECONDS);
});

it('is re-dispatched by reconcile for a pending take that never got a generation', function (): void {
    $take = Takes::pendingWithHold();
    Http::preventStrayRequests();

    app(ReconcileTakeAction::class)->execute($take);

    Queue::assertPushed(SubmitTakeJob::class, fn (SubmitTakeJob $job): bool => $job->takeId === $take->id);
    Http::assertNothingSent();
});
