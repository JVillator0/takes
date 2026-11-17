<?php

declare(strict_types=1);

use App\Enums\Webhooks\SignatureFailure;
use App\Support\Pixelforge\WebhookSignature;

/*
| W-02: the verifier on its own, against the docs' recipe:
| hex HMAC-SHA256 of "<t>.<raw body>" keyed with the signing secret.
*/

const SECRET = 'whsec_unit';
const NOW = 1_762_265_009;

it('matches the documented recipe', function (): void {
    $body = '{"id":"evt_1","type":"ping"}';

    expect(WebhookSignature::compute(NOW, $body, SECRET))
        ->toBe(hash_hmac('sha256', NOW.'.'.$body, SECRET));
});

it('verifies a valid header with whitespace around the parts', function (): void {
    $body = '{"id":"evt_1"}';
    $header = 't='.NOW.', v1='.WebhookSignature::compute(NOW, $body, SECRET);

    expect(WebhookSignature::verify($header, $body, SECRET, 300, NOW))->toBeNull();
});

it('names why a header fails', function (?string $header, ?string $secret, int $now, SignatureFailure $failure): void {
    expect(WebhookSignature::verify($header, '{}', $secret, 300, $now))->toBe($failure);
})->with([
    'no secret' => [fn () => 't='.NOW.',v1=abc', null, NOW, SignatureFailure::SecretMissing],
    'empty header' => ['', SECRET, NOW, SignatureFailure::HeaderMissing],
    'no t' => ['v1=abc', SECRET, NOW, SignatureFailure::InvalidTimestamp],
    'no v1' => [fn () => 't='.NOW, SECRET, NOW, SignatureFailure::SignatureMissing],
    'stale' => [fn () => 't='.NOW.',v1=abc', SECRET, NOW + 301, SignatureFailure::Stale],
    'mismatch' => [fn () => 't='.NOW.',v1=abc', SECRET, NOW, SignatureFailure::Mismatch],
]);
