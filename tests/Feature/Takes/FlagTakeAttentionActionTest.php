<?php

declare(strict_types=1);

use App\Actions\Takes\FlagTakeAttentionAction;
use App\Enums\Takes\AttentionReason;
use App\Models\Take;

/*
| D-054, D-077(c), D-058: one attention_reason per take, and orphan_success
| (absorbed provider cost) is never overwritten by another finding.
*/

function flag(Take $take, AttentionReason $reason): bool
{
    return app(FlagTakeAttentionAction::class)->execute($take, $reason);
}

it('sets a reason on an unflagged take', function (): void {
    $take = Take::factory()->processing()->create();

    expect(flag($take, AttentionReason::InvalidSnapshot))->toBeTrue()
        ->and($take->refresh()->attention_reason)->toBe(AttentionReason::InvalidSnapshot);
});

it('replaces a weaker reason with a later finding', function (): void {
    $take = Take::factory()->processing()->create(['attention_reason' => AttentionReason::InvalidSnapshot]);

    expect(flag($take, AttentionReason::ReconcileExhausted))->toBeTrue()
        ->and($take->refresh()->attention_reason)->toBe(AttentionReason::ReconcileExhausted);
});

it('never drops orphan_success for another reason', function (AttentionReason $reason): void {
    $take = Take::factory()->failed()->create(['attention_reason' => AttentionReason::OrphanSuccess]);
    $stale = $take->replicate()->setRawAttributes($take->getAttributes());
    $stale->attention_reason = null;

    expect(flag($stale, $reason))->toBeFalse()
        ->and($stale->attention_reason)->toBeNull()
        ->and($take->refresh()->attention_reason)->toBe(AttentionReason::OrphanSuccess);
})->with([AttentionReason::InvalidSnapshot, AttentionReason::ReconcileExhausted, AttentionReason::InternalFailure]);
