<?php

declare(strict_types=1);

use App\Events\TakeUpdated;
use Tests\Support\Takes;

/*
| M-23, D-023: customers never see provider cost, tokens, provider names or
| margin. This file covers the TakeUpdated broadcast payload (spec 5); the
| customer page props join it with the workspace (spec 4).
*/

const FORBIDDEN_WORDS = ['pixelforge', 'anthropic', 'cost', 'token', 'margin', 'markup', 'rate', 'provider', 'gen_', 'usage', 'nanos'];

it('broadcasts no provider data for a take in any state', function (string $status): void {
    $take = Takes::in($status);

    $json = strtolower(json_encode((new TakeUpdated($take))->broadcastWith(), JSON_THROW_ON_ERROR));

    foreach (FORBIDDEN_WORDS as $word) {
        expect($json)->not->toContain($word);
    }
})->with(['pending', 'queued', 'processing', 'succeeded', 'failed']);
