<?php

declare(strict_types=1);

use App\Enums\Takes\TakeStatus;
use App\Enums\Takes\TransitionOutcome;
use App\Enums\Webhooks\WebhookOutcome;

/**
 * The full 5 × 5 matrix from spec 3-02. Anything not listed is forbidden,
 * including staying in the same state and every move out of a terminal state.
 */
$allowed = [
    'pending' => ['queued', 'processing', 'succeeded', 'failed'],
    'queued' => ['processing', 'succeeded', 'failed'],
    'processing' => ['succeeded', 'failed'],
    'succeeded' => [],
    'failed' => [],
];

$matrix = [];
foreach (TakeStatus::cases() as $from) {
    foreach (TakeStatus::cases() as $to) {
        $matrix["{$from->value} → {$to->value}"] = [$from, $to, in_array($to->value, $allowed[$from->value], true)];
    }
}

it('allows exactly the transitions of the state machine', function (TakeStatus $from, TakeStatus $to, bool $expected): void {
    expect($from->canBecome($to))->toBe($expected);
})->with($matrix);

it('lists transitions consistent with canBecome', function (): void {
    foreach (TakeStatus::cases() as $from) {
        foreach ($from->transitions() as $to) {
            expect($from->canBecome($to))->toBeTrue();
        }
    }
});

it('marks only succeeded and failed as terminal', function (): void {
    expect(TakeStatus::Succeeded->isTerminal())->toBeTrue()
        ->and(TakeStatus::Failed->isTerminal())->toBeTrue()
        ->and(TakeStatus::Pending->isTerminal())->toBeFalse()
        ->and(TakeStatus::Queued->isTerminal())->toBeFalse()
        ->and(TakeStatus::Processing->isTerminal())->toBeFalse()
        ->and(TakeStatus::nonTerminal())->toBe([TakeStatus::Pending, TakeStatus::Queued, TakeStatus::Processing]);
});

it('has a label and a color for every status', function (TakeStatus $status): void {
    expect($status->label())->not->toBe('')
        ->and($status->color())->toBeIn(['gray', 'info', 'warning', 'success', 'danger']);
})->with(TakeStatus::cases());

it('maps every transition outcome to a webhook outcome', function (TransitionOutcome $outcome): void {
    expect(WebhookOutcome::fromTransition($outcome)->value)->toBe($outcome->value);
})->with(TransitionOutcome::cases());
