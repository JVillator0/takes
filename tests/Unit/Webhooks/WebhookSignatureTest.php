<?php

declare(strict_types=1);

use App\Enums\Webhooks\SignatureFailure;
use App\Support\Pixelforge\WebhookSignature;

/*
| W-02: the verifier on its own, against the docs' recipe:
| hex HMAC-SHA256 of "<t>.<raw body>" keyed with the signing secret.
*/

const SIGNATURE_UNIT_SECRET = 'whsec_unit';
const SIGNATURE_UNIT_NOW = 1_762_265_009;

it('matches the documented recipe', function (): void {
    $body = '{"id":"evt_1","type":"ping"}';

    expect(WebhookSignature::compute(SIGNATURE_UNIT_NOW, $body, SIGNATURE_UNIT_SECRET))
        ->toBe(hash_hmac('sha256', SIGNATURE_UNIT_NOW.'.'.$body, SIGNATURE_UNIT_SECRET));
});

it('verifies a valid header with whitespace around the parts', function (): void {
    $body = '{"id":"evt_1"}';
    $header = 't='.SIGNATURE_UNIT_NOW.', v1='.WebhookSignature::compute(SIGNATURE_UNIT_NOW, $body, SIGNATURE_UNIT_SECRET);

    expect(WebhookSignature::verify($header, $body, SIGNATURE_UNIT_SECRET, 300, SIGNATURE_UNIT_NOW))->toBeNull();
});

it('names why a header fails', function (?string $header, ?string $secret, int $now, SignatureFailure $failure): void {
    expect(WebhookSignature::verify($header, '{}', $secret, 300, $now))->toBe($failure);
})->with([
    'no secret' => [fn () => 't='.SIGNATURE_UNIT_NOW.',v1=abc', null, SIGNATURE_UNIT_NOW, SignatureFailure::SecretMissing],
    'empty header' => ['', SIGNATURE_UNIT_SECRET, SIGNATURE_UNIT_NOW, SignatureFailure::HeaderMissing],
    'no t' => ['v1=abc', SIGNATURE_UNIT_SECRET, SIGNATURE_UNIT_NOW, SignatureFailure::InvalidTimestamp],
    'no v1' => [fn () => 't='.SIGNATURE_UNIT_NOW, SIGNATURE_UNIT_SECRET, SIGNATURE_UNIT_NOW, SignatureFailure::SignatureMissing],
    'stale' => [fn () => 't='.SIGNATURE_UNIT_NOW.',v1=abc', SIGNATURE_UNIT_SECRET, SIGNATURE_UNIT_NOW + 301, SignatureFailure::Stale],
    'mismatch' => [fn () => 't='.SIGNATURE_UNIT_NOW.',v1=abc', SIGNATURE_UNIT_SECRET, SIGNATURE_UNIT_NOW, SignatureFailure::Mismatch],
]);
