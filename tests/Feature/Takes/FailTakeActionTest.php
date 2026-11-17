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
use App\Events\TakeUpdated;
use App\Exceptions\Takes\TakeTransitionNotAllowedException;
use App\Models\LedgerEntry;
use App\Models\Take;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Billing;
use Tests\Support\Takes;

/*
 * FailTakeAction (spec 3-02 issue 3): local failures are free. Fixture: $10.00
 * funded, hold 467,343,750 open on a pending take.
 */

beforeEach(function (): void {
    Queue::fake();
    Event::fake([TakeUpdated::class]);
});

function failTake(Take $take, FailureCode $code, string $message = 'Failed locally.'): TransitionOutcome
{
    return app(FailTakeAction::class)->execute($take, $code, $message);
}

it('fails a pending take free for every local reason', function (FailureCode $code, ?SubmitErrorKind $kind): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-17T12:30:00Z'));
    $take = Takes::pendingWithHold(['last_submit_error_kind' => $kind]);

    expect(failTake($take, $code, 'Rejected at submit.'))->toBe(TransitionOutcome::Applied);

    $take->refresh();
    $usage = Takes::usage($take);
    $customer = $take->customer->refresh();

    expect($take->status)->toBe(TakeStatus::Failed)
        ->and($take->failure_code)->toBe($code)
        ->and($take->failure_message)->toBe('Rejected at submit.')
        ->and($take->completed_at?->toIso8601ZuluString())->toBe('2026-11-17T12:30:00Z')
        ->and($take->last_event_at?->toIso8601ZuluString())->toBe('2026-11-17T12:30:00Z')
        ->and($usage->status)->toBe(UsageStatus::Voided)
        ->and($usage->provider_cost_nanos)->toBe(0)
        ->and($usage->charge_nanos)->toBe(0)
        ->and($customer->balance_nanos)->toBe(Takes::FUNDED_NANOS)
        ->and($customer->held_nanos)->toBe(0)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(0);

    Billing::assertCachedColumnsMatch($customer);
})->with([
    'content_policy' => [FailureCode::ContentPolicy, null],
    'daily_limit' => [FailureCode::DailyLimit, null],
    'validation' => [FailureCode::Validation, null],
    'internal' => [FailureCode::Internal, null],
    'provider_busy' => [FailureCode::ProviderBusy, SubmitErrorKind::Capacity],
    'submit_timeout' => [FailureCode::SubmitTimeout, SubmitErrorKind::Ambiguous],
    'cancelled' => [FailureCode::Cancelled, SubmitErrorKind::Capacity],
]);

it('flags only internal failures for attention', function (FailureCode $code, ?AttentionReason $reason): void {
    $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Capacity]);

    failTake($take, $code);

    expect($take->refresh()->attention_reason)->toBe($reason);
})->with([
    [FailureCode::Internal, AttentionReason::InternalFailure],
    [FailureCode::ProviderBusy, null],
    [FailureCode::ContentPolicy, null],
]);

it('fails an accepted take only for an internal failure (revoked key, D-062)', function (): void {
    $take = Takes::in('processing');

    expect(failTake($take, FailureCode::Internal))->toBe(TransitionOutcome::Applied)
        ->and($take->refresh()->status)->toBe(TakeStatus::Failed)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided)
        ->and($take->customer->refresh()->held_nanos)->toBe(0);
});

it('ignores a submit-phase failure once Pixelforge accepted the take', function (string $status, FailureCode $code): void {
    $take = Takes::in($status);

    expect(failTake($take, $code))->toBe(TransitionOutcome::IgnoredStale)
        ->and($take->refresh()->status->value)->toBe($status)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open)
        ->and($take->customer->refresh()->held_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE);
})->with(['queued', 'processing'])->with([
    FailureCode::SubmitTimeout,
    FailureCode::ProviderBusy,
    FailureCode::ContentPolicy,
]);

it('ignores a second failure and releases the hold only once', function (): void {
    $take = Takes::pendingWithHold();
    failTake($take, FailureCode::Validation, 'First.');
    Event::fake([TakeUpdated::class]);

    expect(failTake($take, FailureCode::Internal, 'Second.'))->toBe(TransitionOutcome::IgnoredSameState)
        ->and($take->refresh()->failure_code)->toBe(FailureCode::Validation)
        ->and($take->failure_message)->toBe('First.')
        ->and($take->attention_reason)->toBeNull()
        ->and($take->customer->refresh()->held_nanos)->toBe(0)
        ->and($take->customer->balance_nanos)->toBe(Takes::FUNDED_NANOS);

    Billing::assertCachedColumnsMatch($take->customer);
    Event::assertNotDispatched(TakeUpdated::class);
});

it('never fails a succeeded take: conflict, flagged, charge kept', function (): void {
    Log::spy();
    $take = Takes::in('succeeded');
    $balance = $take->customer->refresh()->balance_nanos;

    expect(failTake($take, FailureCode::SubmitTimeout))->toBe(TransitionOutcome::IgnoredConflict)
        ->and($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and($take->failure_code)->toBeNull()
        ->and($take->attention_reason)->toBe(AttentionReason::InternalFailure)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Settled)
        ->and($take->customer->refresh()->balance_nanos)->toBe($balance);

    Log::shouldHaveReceived('log')->with('warning', 'takes.local_failure', Mockery::on(
        fn (array $context): bool => $context['outcome'] === 'ignored_conflict' && $context['take_id'] === $take->id,
    ));
});

it('refuses to cancel unless the take waits for capacity', function (?SubmitErrorKind $kind): void {
    $take = Takes::pendingWithHold(['last_submit_error_kind' => $kind]);

    expect(fn () => failTake($take, FailureCode::Cancelled))->toThrow(TakeTransitionNotAllowedException::class);

    expect($take->refresh()->status)->toBe(TakeStatus::Pending)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open)
        ->and($take->customer->refresh()->held_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE);

    Event::assertNotDispatched(TakeUpdated::class);
})->with([
    'ambiguous submit in doubt' => SubmitErrorKind::Ambiguous,
    'never attempted' => null,
]);

it('refuses to cancel a take that left the capacity state, without flagging it', function (string $status): void {
    $take = Takes::in($status);
    $before = $take->only(['status', 'failure_code', 'attention_reason', 'last_event_at']);

    expect(fn () => failTake($take, FailureCode::Cancelled))->toThrow(TakeTransitionNotAllowedException::class);

    expect($take->refresh()->only(array_keys($before)))->toEqual($before)
        ->and($take->attention_reason)->toBeNull();
})->with(['queued', 'processing', 'succeeded', 'failed']);

it('leaves the caller model clean when saving the take throws', function (): void {
    $take = Takes::pendingWithHold();
    Take::saving(fn (): never => throw new RuntimeException('Saving the take failed.'));

    try {
        expect(fn () => failTake($take, FailureCode::ContentPolicy))->toThrow(RuntimeException::class);
    } finally {
        Take::flushEventListeners();
    }

    expect($take->isDirty())->toBeFalse()
        ->and($take->status)->toBe(TakeStatus::Pending)
        ->and($take->failure_code)->toBeNull()
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open)
        ->and($take->customer->refresh()->held_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE);

    Event::assertNotDispatched(TakeUpdated::class);
});

it('refuses provider outcome codes', function (FailureCode $code): void {
    $take = Takes::pendingWithHold();

    expect(fn () => failTake($take, $code))->toThrow(TakeTransitionNotAllowedException::class)
        ->and($take->refresh()->status)->toBe(TakeStatus::Pending);
})->with([FailureCode::GenerationFailed, FailureCode::InternalError]);

it('broadcasts the failure only after the outer transaction commits', function (): void {
    $take = Takes::pendingWithHold();

    DB::transaction(function () use ($take): void {
        failTake($take, FailureCode::ContentPolicy);

        Event::assertNotDispatched(TakeUpdated::class);
    });

    Event::assertDispatched(TakeUpdated::class, fn (TakeUpdated $event): bool => $event->take->is($take));
    Queue::assertNothingPushed();
});

it('logs the outcome with the take and the failure code', function (): void {
    Log::spy();
    $take = Takes::pendingWithHold();

    failTake($take, FailureCode::DailyLimit);

    Log::shouldHaveReceived('log')->with('info', 'takes.local_failure', [
        'take_id' => $take->id,
        'failure_code' => 'daily_limit',
        'from' => 'pending',
        'to' => 'failed',
        'outcome' => 'applied',
    ]);
});

it('lets a provider failure win over a later local one', function (): void {
    $take = Takes::pendingWithHold();
    app(ApplyTakeSnapshotAction::class)->execute($take, Takes::snapshot($take, 'failed'));

    expect(failTake($take, FailureCode::SubmitTimeout))->toBe(TransitionOutcome::IgnoredSameState)
        ->and($take->refresh()->failure_code)->toBe(FailureCode::InternalError);
});
