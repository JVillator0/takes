<?php

declare(strict_types=1);

use App\Enums\Takes\TransitionOutcome;
use App\Enums\Webhooks\WebhookOutcome;

/*
| W-08, D-049: WebhookOutcome is every TransitionOutcome plus the four
| delivery-level outcomes, and nothing else (no invalid_signature).
*/

it('is the transition outcomes plus duplicate, ping, unknown_take and ignored_type', function (): void {
    $transitions = array_map(fn (TransitionOutcome $outcome): string => $outcome->value, TransitionOutcome::cases());

    expect(array_map(fn (WebhookOutcome $outcome): string => $outcome->value, WebhookOutcome::cases()))
        ->toEqualCanonicalizing([...$transitions, 'duplicate', 'ping', 'unknown_take', 'ignored_type']);
});

it('maps every transition outcome to the same webhook outcome', function (TransitionOutcome $outcome): void {
    expect(WebhookOutcome::fromTransition($outcome)->value)->toBe($outcome->value);
})->with(TransitionOutcome::cases());
