<?php

declare(strict_types=1);

use App\Actions\Takes\ApplyTakeSnapshotAction;
use App\Actions\Takes\FailTakeAction;
use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeStatus;
use App\Enums\Takes\TransitionOutcome;
use App\Events\TakeUpdated;
use App\Exceptions\Takes\SnapshotRatesMissingException;
use App\Jobs\PersistTakeImageJob;
use App\Jobs\ReconcileTakeJob;
use App\Models\LedgerEntry;
use App\Models\Take;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Billing;
use Tests\Support\Takes;

/*
 * ApplyTakeSnapshotAction (spec 3-02 issue 3, spec 5 outcome matrix).
 * Money fixture: $10.00 funded, hold 467,343,750; the docs' succeeded
 * generation costs 305,510,000 and charges exactly 381,887,500.
 */

beforeEach(function (): void {
    Queue::fake();
    Event::fake([TakeUpdated::class]);
});

function applySnapshot(Take $take, GenerationSnapshotDTO $snapshot): TransitionOutcome
{
    return app(ApplyTakeSnapshotAction::class)->execute($take, $snapshot);
}

/**
 * The same snapshot with some constructor arguments replaced, for payloads
 * GenerationSnapshotDTO::fromArray() would refuse to build.
 *
 * @param  array<string, mixed>  $changes
 */
function rebuildSnapshot(GenerationSnapshotDTO $snapshot, array $changes): GenerationSnapshotDTO
{
    return new GenerationSnapshotDTO(...[...get_object_vars($snapshot), ...$changes]);
}

function chargeCount(): int
{
    return LedgerEntry::query()->where('type', 'charge')->count();
}

describe('outcome matrix', function (): void {
    it('applies or ignores every provider status from every take status', function (string $from, string $incoming, TransitionOutcome $outcome, string $final): void {
        $take = Takes::in($from);

        expect(applySnapshot($take, Takes::snapshot($take, $incoming)))->toBe($outcome)
            ->and($take->refresh()->status->value)->toBe($final)
            ->and(chargeCount())->toBe($final === 'succeeded' ? 1 : 0);

        Billing::assertCachedColumnsMatch($take->customer);
    })->with([
        // Forward, including jumps: pending → succeeded before queued/processing.
        ['pending', 'queued', TransitionOutcome::Applied, 'queued'],
        ['pending', 'processing', TransitionOutcome::Applied, 'processing'],
        ['pending', 'succeeded', TransitionOutcome::Applied, 'succeeded'],
        ['pending', 'failed', TransitionOutcome::Applied, 'failed'],
        ['queued', 'processing', TransitionOutcome::Applied, 'processing'],
        ['queued', 'succeeded', TransitionOutcome::Applied, 'succeeded'],
        ['queued', 'failed', TransitionOutcome::Applied, 'failed'],
        ['processing', 'succeeded', TransitionOutcome::Applied, 'succeeded'],
        ['processing', 'failed', TransitionOutcome::Applied, 'failed'],
        // Same status from a new event.
        ['queued', 'queued', TransitionOutcome::IgnoredSameState, 'queued'],
        ['processing', 'processing', TransitionOutcome::IgnoredSameState, 'processing'],
        ['succeeded', 'succeeded', TransitionOutcome::IgnoredSameState, 'succeeded'],
        ['failed', 'failed', TransitionOutcome::IgnoredSameState, 'failed'],
        // Backwards.
        ['processing', 'queued', TransitionOutcome::IgnoredStale, 'processing'],
        ['succeeded', 'queued', TransitionOutcome::IgnoredStale, 'succeeded'],
        ['succeeded', 'processing', TransitionOutcome::IgnoredStale, 'succeeded'],
        ['failed', 'queued', TransitionOutcome::IgnoredStale, 'failed'],
        ['failed', 'processing', TransitionOutcome::IgnoredStale, 'failed'],
        // Terminal → the other terminal (provider failure, not submit_timeout).
        ['succeeded', 'failed', TransitionOutcome::IgnoredConflict, 'succeeded'],
        ['failed', 'succeeded', TransitionOutcome::IgnoredConflict, 'failed'],
    ]);

    it('advances last_event_at only when a snapshot advances or confirms the state (D-077)', function (string $from, string $incoming, TransitionOutcome $outcome, bool $advances): void {
        $take = Takes::in($from);
        $before = $take->last_event_at;

        $this->travel(5)->minutes();

        expect(applySnapshot($take, Takes::snapshot($take, $incoming)))->toBe($outcome)
            ->and($take->refresh()->last_event_at?->greaterThan($before))->toBe($advances);
    })->with([
        'applied' => ['queued', 'processing', TransitionOutcome::Applied, true],
        'same state' => ['processing', 'processing', TransitionOutcome::IgnoredSameState, true],
        // A late queued redelivery must not keep a stalled processing take out of the stuck rule.
        'stale' => ['processing', 'queued', TransitionOutcome::IgnoredStale, false],
        'conflict' => ['succeeded', 'failed', TransitionOutcome::IgnoredConflict, false],
    ]);

    it('flags a succeeded take contradicted by a failed snapshot and keeps the charge', function (): void {
        Log::spy();
        $take = Takes::in('succeeded');
        $balance = $take->customer->refresh()->balance_nanos;

        expect(applySnapshot($take, Takes::snapshot($take, 'failed')))->toBe(TransitionOutcome::IgnoredConflict)
            ->and($take->refresh()->status)->toBe(TakeStatus::Succeeded)
            ->and($take->attention_reason)->toBe(AttentionReason::InternalFailure)
            ->and($take->failure_code)->toBeNull()
            ->and(Takes::usage($take)->status)->toBe(UsageStatus::Settled)
            ->and($take->customer->refresh()->balance_nanos)->toBe($balance)
            ->and(chargeCount())->toBe(1);

        Log::shouldHaveReceived('log')->with('warning', 'takes.snapshot', Mockery::on(
            fn (array $context): bool => $context['outcome'] === 'ignored_conflict' && $context['take_id'] === $take->id,
        ));
    });
});

describe('orphan success vs conflict', function (): void {
    it('records a late success after submit_timeout as an orphan: still failed, still free', function (): void {
        Log::spy();
        $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Ambiguous]);
        app(FailTakeAction::class)->execute($take, FailureCode::SubmitTimeout, 'Submit window expired.');
        Event::fake([TakeUpdated::class]);

        expect(applySnapshot($take, Takes::snapshot($take, 'succeeded')))->toBe(TransitionOutcome::OrphanSuccess);

        $take->refresh();
        $usage = Takes::usage($take);

        expect($take->status)->toBe(TakeStatus::Failed)
            ->and($take->failure_code)->toBe(FailureCode::SubmitTimeout)
            ->and($take->attention_reason)->toBe(AttentionReason::OrphanSuccess)
            ->and($take->pixelforge_id)->toBe(Takes::generationId($take))
            ->and($take->image_url)->toBeNull()
            ->and($usage->status)->toBe(UsageStatus::Voided)
            ->and($usage->provider_cost_nanos)->toBe(0)
            ->and($usage->charge_nanos)->toBe(0)
            ->and($usage->flags)->toBe(['orphan'])
            ->and($take->customer->refresh()->balance_nanos)->toBe(Takes::FUNDED_NANOS)
            ->and($take->customer->held_nanos)->toBe(0)
            ->and(chargeCount())->toBe(0);

        Queue::assertNotPushed(PersistTakeImageJob::class);
        Event::assertNotDispatched(TakeUpdated::class);
        Log::shouldHaveReceived('log')->with('warning', 'takes.snapshot', Mockery::on(
            fn (array $context): bool => $context['outcome'] === 'orphan_success',
        ));
    });

    it('flags the orphan once when the late success is replayed', function (): void {
        $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Ambiguous]);
        app(FailTakeAction::class)->execute($take, FailureCode::SubmitTimeout, 'Submit window expired.');

        applySnapshot($take, Takes::snapshot($take, 'succeeded'));

        expect(applySnapshot($take, Takes::snapshot($take, 'succeeded')))->toBe(TransitionOutcome::OrphanSuccess)
            ->and(Takes::usage($take)->flags)->toBe(['orphan']);
    });

    it('records the absorbed provider cost on the voided usage, outside the ledger (#102)', function (): void {
        $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Ambiguous]);
        app(FailTakeAction::class)->execute($take, FailureCode::SubmitTimeout, 'Submit window expired.');

        applySnapshot($take, Takes::snapshot($take, 'succeeded'));
        $usage = Takes::usage($take);

        // The docs' succeeded generation: units × its own rates = 305,510,000, completed 2026-11-04 UTC.
        expect($usage->status)->toBe(UsageStatus::Voided)
            ->and($usage->absorbed_cost_nanos)->toBe(305_510_000)
            ->and($usage->reasoning_tokens)->toBe(3_184)
            ->and($usage->billing_date?->toDateString())->toBe('2026-11-04')
            ->and($usage->external_id)->toBe(Takes::generationId($take))
            ->and($usage->provider_cost_nanos)->toBe(0)
            ->and($usage->charge_nanos)->toBe(0)
            ->and(chargeCount())->toBe(0)
            ->and($take->customer->refresh()->balance_nanos)->toBe(Takes::FUNDED_NANOS);

        // A replay with other rates does not re-price what was already absorbed.
        applySnapshot($take, Takes::snapshot($take, 'succeeded', ['rates' => [
            'input_text_tokens' => '1.00', 'input_image_tokens' => '1.00', 'output_tokens' => '1.00', 'reasoning_tokens' => '1.00',
        ]]));

        expect(Takes::usage($take)->absorbed_cost_nanos)->toBe(305_510_000);
    });

    it('throws for an unpriced orphan without rates and writes nothing, so the caller fetches the generation', function (): void {
        $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Ambiguous]);
        app(FailTakeAction::class)->execute($take, FailureCode::SubmitTimeout, 'Submit window expired.');

        expect(fn () => applySnapshot($take, rebuildSnapshot(Takes::snapshot($take, 'succeeded'), ['rates' => null])))
            ->toThrow(SnapshotRatesMissingException::class);

        $usage = Takes::usage($take);

        expect($take->refresh()->attention_reason)->toBeNull()
            ->and($take->pixelforge_id)->toBeNull()
            ->and($usage->flags)->toBe([])
            ->and($usage->absorbed_cost_nanos)->toBeNull();
    });

    it('re-flags an already priced orphan whose replay has no rates, without throwing', function (): void {
        $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Ambiguous]);
        app(FailTakeAction::class)->execute($take, FailureCode::SubmitTimeout, 'Submit window expired.');
        applySnapshot($take, Takes::snapshot($take, 'succeeded'));

        expect(applySnapshot($take, rebuildSnapshot(Takes::snapshot($take, 'succeeded'), ['rates' => null])))->toBe(TransitionOutcome::OrphanSuccess)
            ->and(Takes::usage($take)->absorbed_cost_nanos)->toBe(305_510_000);
    });

    it('records a late success after an internal failure of an accepted take as an orphan (D-077)', function (): void {
        $take = Takes::in('processing');
        app(FailTakeAction::class)->execute($take, FailureCode::Internal, 'Revoked key while reconciling.');

        expect(applySnapshot($take, Takes::snapshot($take, 'succeeded')))->toBe(TransitionOutcome::OrphanSuccess);

        $take->refresh();
        $usage = Takes::usage($take);

        expect($take->status)->toBe(TakeStatus::Failed)
            ->and($take->failure_code)->toBe(FailureCode::Internal)
            ->and($take->attention_reason)->toBe(AttentionReason::OrphanSuccess)
            ->and($usage->status)->toBe(UsageStatus::Voided)
            ->and($usage->flags)->toBe(['orphan'])
            ->and($take->customer->refresh()->balance_nanos)->toBe(Takes::FUNDED_NANOS)
            ->and($take->customer->held_nanos)->toBe(0)
            ->and(chargeCount())->toBe(0);
    });

    it('keeps an orphan after an internal failure of an accepted take on replay', function (): void {
        $take = Takes::in('processing');
        app(FailTakeAction::class)->execute($take, FailureCode::Internal, 'Revoked key while reconciling.');
        $snapshot = Takes::snapshot($take->refresh(), 'succeeded');

        expect(applySnapshot($take, $snapshot))->toBe(TransitionOutcome::OrphanSuccess)
            ->and(applySnapshot($take, $snapshot))->toBe(TransitionOutcome::OrphanSuccess)
            ->and(Takes::usage($take)->flags)->toBe(['orphan']);
    });

    it('records a late success after an internal failure of an in-doubt submit as an orphan, on every replay (D-082)', function (array $submit, array $leadIn, array $expected): void {
        // Pixelforge may hold the generation under the idempotency key.
        $take = Takes::pendingWithHold($submit);
        app(FailTakeAction::class)->execute($take, FailureCode::Internal, 'Revoked key while retrying an ambiguous submit.');

        $outcomes = [];

        foreach ([...$leadIn, 'succeeded', 'succeeded'] as $status) {
            $outcomes[] = applySnapshot($take, Takes::snapshot($take, $status));
        }

        $take->refresh();
        $usage = Takes::usage($take);

        expect($outcomes)->toBe([...$expected, TransitionOutcome::OrphanSuccess, TransitionOutcome::OrphanSuccess])
            ->and($take->status)->toBe(TakeStatus::Failed)
            ->and($take->failure_code)->toBe(FailureCode::Internal)
            ->and($take->submitWasInDoubt())->toBeTrue()
            ->and($take->pixelforge_id)->not->toBeNull()
            ->and($take->attention_reason)->toBe(AttentionReason::OrphanSuccess)
            ->and($usage->status)->toBe(UsageStatus::Voided)
            ->and($usage->flags)->toBe(['orphan'])
            ->and($take->customer->refresh()->balance_nanos)->toBe(Takes::FUNDED_NANOS)
            ->and($take->customer->held_nanos)->toBe(0)
            ->and(chargeCount())->toBe(0);
    })->with([
        'ambiguous answer' => [['last_submit_error_kind' => SubmitErrorKind::Ambiguous, 'submit_attempts' => 2]],
        // The worker died during the call: counted, never answered (same rule as SubmitTakeJob).
        'attempt never answered' => [['last_submit_error_kind' => null, 'submit_attempts' => 1]],
    ])->with([
        'success applied twice' => [[], []],
        'late queued first' => [['queued'], [TransitionOutcome::IgnoredStale]],
        'late provider failure first' => [['failed'], [TransitionOutcome::IgnoredSameState]],
    ]);

    it('never turns an internal failure before acceptance into an orphan, whatever arrives first', function (array $submit, array $leadIn, array $expected): void {
        // Capacity answers (429/503) only, or no attempt at all: nothing was created (D-082).
        $take = Takes::pendingWithHold($submit);
        app(FailTakeAction::class)->execute($take, FailureCode::Internal, 'Rejected at submit.');

        $outcomes = [];

        foreach ([...$leadIn, 'succeeded', 'succeeded'] as $status) {
            $outcomes[] = applySnapshot($take, Takes::snapshot($take, $status));
        }

        $take->refresh();

        expect($outcomes)->toBe([...$expected, TransitionOutcome::IgnoredConflict, TransitionOutcome::IgnoredConflict])
            // The failed take never adopts the late generation, so every replay reads the same fact.
            ->and($take->pixelforge_id)->toBeNull()
            ->and($take->attention_reason)->toBe(AttentionReason::InternalFailure)
            ->and(Takes::usage($take)->flags)->toBe([])
            ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided)
            ->and(chargeCount())->toBe(0);
    })->with([
        'no attempt yet' => [['last_submit_error_kind' => null, 'submit_attempts' => 0]],
        'capacity answers only' => [['last_submit_error_kind' => SubmitErrorKind::Capacity, 'submit_attempts' => 3]],
    ])->with([
        'success applied twice' => [[], []],
        'late queued first' => [['queued'], [TransitionOutcome::IgnoredStale]],
        'late provider failure first' => [['failed'], [TransitionOutcome::IgnoredSameState]],
    ]);

    it('never lets a weaker finding replace orphan_success', function (): void {
        $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Ambiguous]);
        app(FailTakeAction::class)->execute($take, FailureCode::SubmitTimeout, 'Submit window expired.');
        applySnapshot($take, Takes::snapshot($take, 'succeeded'));

        expect(applySnapshot($take, Takes::snapshot($take, 'succeeded', ['id' => 'gen_someoneelse'])))->toBe(TransitionOutcome::InvalidSnapshot)
            ->and($take->refresh()->attention_reason)->toBe(AttentionReason::OrphanSuccess);
    });

    it('treats a late success after any other local failure as a conflict', function (FailureCode $code): void {
        $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Capacity]);
        app(FailTakeAction::class)->execute($take, $code, 'Failed locally.');

        expect(applySnapshot($take, Takes::snapshot($take, 'succeeded')))->toBe(TransitionOutcome::IgnoredConflict)
            ->and($take->refresh()->status)->toBe(TakeStatus::Failed)
            ->and($take->attention_reason)->toBe(AttentionReason::InternalFailure)
            ->and(Takes::usage($take)->flags)->toBe([])
            ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided)
            ->and($take->customer->refresh()->balance_nanos)->toBe(Takes::FUNDED_NANOS)
            ->and(chargeCount())->toBe(0);
    })->with([
        FailureCode::ProviderBusy,
        FailureCode::Cancelled,
        FailureCode::ContentPolicy,
        FailureCode::DailyLimit,
        FailureCode::Validation,
        FailureCode::Internal,
    ]);
});

describe('settlement', function (): void {
    it('charges the docs example exactly: cost × 1.25, hold released', function (): void {
        $take = Takes::pendingWithHold();

        expect(applySnapshot($take, Takes::snapshot($take, 'succeeded')))->toBe(TransitionOutcome::Applied);

        $take->refresh();
        $usage = Takes::usage($take);
        $customer = $take->customer->refresh();

        expect($usage->status)->toBe(UsageStatus::Settled)
            ->and($usage->provider_cost_nanos)->toBe(Takes::DOCS_COST)
            ->and($usage->charge_nanos)->toBe(Takes::DOCS_CHARGE)
            ->and($usage->charge_nanos * 4)->toBe($usage->provider_cost_nanos * 5)
            ->and($usage->units)->toEqual(['input_text' => 12, 'input_image' => 765, 'output' => 6_450, 'reasoning' => 3_184])
            ->and($usage->external_id)->toBe(Takes::generationId($take))
            ->and($usage->billing_date?->toDateString())->toBe('2026-11-04')
            ->and($usage->chargeEntry?->amount_nanos)->toBe(-Takes::DOCS_CHARGE)
            ->and($customer->balance_nanos)->toBe(Takes::FUNDED_NANOS - Takes::DOCS_CHARGE)
            ->and($customer->held_nanos)->toBe(0)
            ->and($take->completed_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
            ->and($take->started_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z')
            ->and($take->image_url)->toStartWith('https://pixelforge.promptedit.dev/images/')
            ->and($take->image_url_expires_at?->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z');

        Billing::assertCachedColumnsMatch($customer);
        $this->artisan('billing:verify')->assertSuccessful();
    });

    it('settles with the terminal snapshot rates, not the rates used for the hold (D-055)', function (): void {
        $take = Takes::pendingWithHold();
        // Output at $20.00 / 1M instead of the $40.00 snapshotted at open.
        $snapshot = Takes::snapshot($take, 'succeeded', ['rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '20.00',
            'reasoning_tokens' => '12.50',
        ]]);

        applySnapshot($take, $snapshot);

        // 305,510,000 − 6,450 × 20,000 = 176,510,000; × 1.25 = 220,637,500.
        expect(Takes::usage($take)->rates['output'])->toBe(20_000_000_000)
            ->and(Takes::usage($take)->provider_cost_nanos)->toBe(176_510_000)
            ->and(Takes::usage($take)->charge_nanos)->toBe(220_637_500);
    });

    it('charges once when the same success is applied twice', function (): void {
        $take = Takes::pendingWithHold();
        $snapshot = Takes::snapshot($take, 'succeeded');

        expect(applySnapshot($take, $snapshot))->toBe(TransitionOutcome::Applied)
            ->and(applySnapshot($take, $snapshot))->toBe(TransitionOutcome::IgnoredSameState)
            ->and(chargeCount())->toBe(1)
            ->and($take->customer->refresh()->balance_nanos)->toBe(Takes::FUNDED_NANOS - Takes::DOCS_CHARGE);

        Billing::assertCachedColumnsMatch($take->customer);
    });

    it('keeps the take and the charge when processing and the create response arrive after the success', function (): void {
        $take = Takes::pendingWithHold();

        // Webhook first: found by metadata.app_take_id, the take has no pixelforge_id yet.
        expect($take->pixelforge_id)->toBeNull()
            ->and(applySnapshot($take, Takes::snapshot($take, 'succeeded')))->toBe(TransitionOutcome::Applied)
            ->and(applySnapshot($take, Takes::snapshot($take, 'processing')))->toBe(TransitionOutcome::IgnoredStale)
            // The late 202 (replayed create response) is a no-op.
            ->and(applySnapshot($take, Takes::snapshot($take, 'queued', replayed: true)))->toBe(TransitionOutcome::IgnoredStale)
            ->and($take->refresh()->status)->toBe(TakeStatus::Succeeded)
            ->and($take->pixelforge_id)->toBe(Takes::generationId($take))
            ->and(chargeCount())->toBe(1)
            ->and($take->customer->refresh()->balance_nanos)->toBe(Takes::FUNDED_NANOS - Takes::DOCS_CHARGE);
    });

    it('throws when a settling snapshot has no rates and writes nothing', function (): void {
        $take = Takes::pendingWithHold();
        $snapshot = rebuildSnapshot(Takes::snapshot($take, 'succeeded'), ['rates' => null]);

        expect(fn () => applySnapshot($take, $snapshot))->toThrow(SnapshotRatesMissingException::class);

        // The caller's model carries no unsaved transition it could persist later.
        expect($take->isDirty())->toBeFalse()
            ->and($take->status)->toBe(TakeStatus::Pending);

        $take->refresh();

        expect($take->status)->toBe(TakeStatus::Pending)
            ->and($take->pixelforge_id)->toBeNull()
            ->and($take->last_event_at)->toBeNull()
            ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open)
            ->and($take->customer->refresh()->held_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE)
            ->and(chargeCount())->toBe(0);

        Queue::assertNothingPushed();
        Event::assertNotDispatched(TakeUpdated::class);
    });

    it('leaves the caller model clean when settlement throws mid-transition', function (): void {
        $take = Takes::pendingWithHold();
        $usage = Takes::usage($take);
        // A charge row already exists for this usage: SettleUsageAction's insert
        // hits the unique (usage_record_id) where type = charge index and throws.
        DB::table('ledger_entries')->insert([
            'customer_id' => $take->customer_id,
            'type' => 'charge',
            'amount_nanos' => -1,
            'usage_record_id' => $usage->id,
        ]);

        expect(fn () => applySnapshot($take, Takes::snapshot($take, 'succeeded')))->toThrow(QueryException::class);

        // advance() had already set status = succeeded on the model: the guard discarded it.
        expect($take->isDirty())->toBeFalse()
            ->and($take->status)->toBe(TakeStatus::Pending)
            ->and($take->pixelforge_id)->toBeNull()
            ->and($take->refresh()->status)->toBe(TakeStatus::Pending)
            ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open);
    });

    it('leaves the caller model clean when saving the take throws after the void', function (): void {
        $take = Takes::in('processing');
        Take::saving(fn (): never => throw new RuntimeException('Saving the take failed.'));

        try {
            expect(fn () => applySnapshot($take, Takes::snapshot($take, 'failed')))->toThrow(RuntimeException::class);
        } finally {
            Take::flushEventListeners();
        }

        expect($take->isDirty())->toBeFalse()
            ->and($take->status)->toBe(TakeStatus::Processing)
            ->and($take->failure_code)->toBeNull()
            ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open)
            ->and($take->customer->refresh()->held_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE);
    });

    it('does not need rates when nothing settles', function (): void {
        $take = Takes::in('succeeded');

        expect(applySnapshot($take, rebuildSnapshot(Takes::snapshot($take, 'succeeded'), ['rates' => null])))
            ->toBe(TransitionOutcome::IgnoredSameState)
            ->and(applySnapshot($take, rebuildSnapshot(Takes::snapshot($take, 'processing'), ['rates' => null])))
            ->toBe(TransitionOutcome::IgnoredStale);
    });
});

describe('failed is free', function (): void {
    it('voids the usage and releases the hold on a provider failure', function (string $code, FailureCode $failureCode): void {
        $take = Takes::in('processing');
        $snapshot = Takes::snapshot($take, 'failed', ['error' => ['code' => $code, 'message' => 'Provider said no.']]);

        expect(applySnapshot($take, $snapshot))->toBe(TransitionOutcome::Applied);

        $take->refresh();
        $usage = Takes::usage($take);

        expect($take->status)->toBe(TakeStatus::Failed)
            ->and($take->failure_code)->toBe($failureCode)
            ->and($take->failure_message)->toBe('Provider said no.')
            ->and($take->completed_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
            ->and($usage->status)->toBe(UsageStatus::Voided)
            ->and($usage->provider_cost_nanos)->toBe(0)
            ->and($usage->charge_nanos)->toBe(0)
            ->and($take->customer->refresh()->balance_nanos)->toBe(Takes::FUNDED_NANOS)
            ->and($take->customer->held_nanos)->toBe(0)
            ->and(chargeCount())->toBe(0);

        Billing::assertCachedColumnsMatch($take->customer);
    })->with([
        ['internal_error', FailureCode::InternalError],
        ['generation_failed', FailureCode::GenerationFailed],
        // An undocumented provider code is still a provider failure.
        ['something_new', FailureCode::GenerationFailed],
    ]);
});

describe('invalid snapshots', function (): void {
    it('changes nothing but the attention flag', function (Closure $build): void {
        Log::spy();
        $take = Takes::in('queued');
        Queue::fake();
        Event::fake([TakeUpdated::class]);
        $before = $take->only(['status', 'pixelforge_id', 'last_event_at', 'started_at', 'completed_at']);

        expect(applySnapshot($take, $build($take)))->toBe(TransitionOutcome::InvalidSnapshot);

        $take->refresh();

        expect($take->only(array_keys($before)))->toEqual($before)
            ->and($take->attention_reason)->toBe(AttentionReason::InvalidSnapshot)
            ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open)
            ->and($take->customer->refresh()->held_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE)
            ->and(chargeCount())->toBe(0);

        Event::assertNotDispatched(TakeUpdated::class);
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('log')->with('critical', 'takes.snapshot', Mockery::on(
            fn (array $context): bool => $context['outcome'] === 'invalid_snapshot' && isset($context['reason']),
        ));
    })->with([
        'metadata names another take' => fn (Take $take): GenerationSnapshotDTO => Takes::snapshot($take, 'succeeded', ['metadata' => ['app_take_id' => '01JC2Z8Q4N6V0M3H7X5T9R2B1K']]),
        'another generation id' => fn (Take $take): GenerationSnapshotDTO => Takes::snapshot($take, 'succeeded', ['id' => 'gen_someoneelse']),
        'succeeded without usage' => fn (Take $take): GenerationSnapshotDTO => rebuildSnapshot(Takes::snapshot($take, 'succeeded'), ['usage' => null]),
        'succeeded without image_url' => fn (Take $take): GenerationSnapshotDTO => rebuildSnapshot(Takes::snapshot($take, 'succeeded'), ['imageUrl' => null]),
        'failed without an error' => fn (Take $take): GenerationSnapshotDTO => rebuildSnapshot(Takes::snapshot($take, 'failed'), ['errorCode' => null]),
        'finished without completed_at' => fn (Take $take): GenerationSnapshotDTO => rebuildSnapshot(Takes::snapshot($take, 'failed'), ['completedAt' => null]),
    ]);

    it('refuses a generation that nothing ties to the take', function (): void {
        $take = Takes::pendingWithHold();
        $snapshot = Takes::snapshot($take, 'succeeded', ['metadata' => []]);

        expect(applySnapshot($take, $snapshot))->toBe(TransitionOutcome::InvalidSnapshot)
            ->and($take->refresh()->pixelforge_id)->toBeNull()
            ->and($take->status)->toBe(TakeStatus::Pending);
    });
});

describe('after commit', function (): void {
    it('dispatches TakeUpdated and PersistTakeImageJob only when the outer transaction commits', function (): void {
        $take = Takes::pendingWithHold();

        DB::transaction(function () use ($take): void {
            applySnapshot($take, Takes::snapshot($take, 'succeeded'));

            Queue::assertNothingPushed();
            Event::assertNotDispatched(TakeUpdated::class);
        });

        Queue::assertPushed(PersistTakeImageJob::class, fn (PersistTakeImageJob $job): bool => $job->takeId === $take->id);
        Event::assertDispatched(TakeUpdated::class, fn (TakeUpdated $event): bool => $event->take->is($take));
        // Accepted straight into a terminal state: nothing left to reconcile.
        Queue::assertNotPushed(ReconcileTakeJob::class);
    });

    it('dispatches nothing and settles nothing when the outer transaction rolls back', function (): void {
        $take = Takes::pendingWithHold();

        try {
            DB::transaction(function () use ($take): void {
                applySnapshot($take, Takes::snapshot($take, 'succeeded'));

                throw new RuntimeException('The webhook handler failed after applying.');
            });
        } catch (RuntimeException) {
        }

        expect($take->refresh()->status)->toBe(TakeStatus::Pending)
            ->and(chargeCount())->toBe(0);

        Queue::assertNothingPushed();
        Event::assertNotDispatched(TakeUpdated::class);
    });

    it('starts the reconcile chain when Pixelforge first accepts the take', function (): void {
        $take = Takes::pendingWithHold();

        applySnapshot($take, Takes::snapshot($take, 'queued'));
        applySnapshot($take, Takes::snapshot($take, 'processing'));

        Queue::assertPushed(ReconcileTakeJob::class, 1);
        Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id && $job->delay === 60);
        Event::assertDispatchedTimes(TakeUpdated::class, 2);
        Queue::assertNotPushed(PersistTakeImageJob::class);
    });

    it('dispatches nothing for an ignored snapshot', function (): void {
        $take = Takes::in('succeeded');
        Queue::fake();
        Event::fake([TakeUpdated::class]);

        applySnapshot($take, Takes::snapshot($take, 'succeeded'));
        applySnapshot($take, Takes::snapshot($take, 'processing'));
        applySnapshot($take, Takes::snapshot($take, 'failed'));

        Queue::assertNothingPushed();
        Event::assertNotDispatched(TakeUpdated::class);
    });

    it('logs every outcome with the take, the generation and the statuses', function (): void {
        Log::spy();
        $take = Takes::pendingWithHold();

        applySnapshot($take, Takes::snapshot($take, 'processing'));

        Log::shouldHaveReceived('log')->with('info', 'takes.snapshot', [
            'take_id' => $take->id,
            'generation_id' => Takes::generationId($take->refresh()),
            'from' => 'pending',
            'incoming' => 'processing',
            'to' => 'processing',
            'outcome' => 'applied',
            'replayed' => false,
        ]);
    });
});

it('reads the take under the lock, not the caller copy', function (): void {
    $take = Takes::pendingWithHold();
    $stale = Take::query()->findOrFail($take->id);

    applySnapshot($take, Takes::snapshot($take, 'succeeded'));

    // $stale still says pending; the action re-reads it and ignores the replay.
    expect($stale->status)->toBe(TakeStatus::Pending)
        ->and(applySnapshot($stale, Takes::snapshot($take->refresh(), 'succeeded')))->toBe(TransitionOutcome::IgnoredSameState)
        ->and($stale->status)->toBe(TakeStatus::Succeeded)
        ->and(chargeCount())->toBe(1);
});

it('keeps the provider completion time on the take', function (): void {
    $take = Takes::pendingWithHold();
    $this->travelTo(CarbonImmutable::parse('2026-11-17T09:00:00Z'));

    applySnapshot($take, Takes::snapshot($take, 'succeeded'));

    expect($take->refresh()->completed_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
        ->and($take->last_event_at?->toIso8601ZuluString())->toBe('2026-11-17T09:00:00Z');
});
