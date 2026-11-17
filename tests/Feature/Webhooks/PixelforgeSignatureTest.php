<?php

declare(strict_types=1);

use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Support\PixelforgeWebhook;

/*
| W-02, W-03 (D-056): the signature is checked on the raw body; anything that
| does not verify answers 401 with a warning and writes nothing at all.
*/

beforeEach(function (): void {
    PixelforgeWebhook::configure();
    Log::spy();
});

function expectRejected(TestResponse $response, string $reason): void
{
    $response->assertStatus(401)->assertExactJson(['error' => 'invalid_signature']);

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'invalid signature') && $context['reason'] === $reason,
    )->once();
}

it('rejects a delivery without the signature header', function (): void {
    expectRejected(PixelforgeWebhook::post(PixelforgeWebhook::event('ping'), signature: null), 'header_missing');
});

it('rejects a signature made with another secret', function (): void {
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event());

    expectRejected(PixelforgeWebhook::post($body, PixelforgeWebhook::signature($body, secret: 'whsec_someone_else')), 'mismatch');
});

it('rejects a tampered body signed before the change', function (): void {
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event());
    $signature = PixelforgeWebhook::signature($body);

    expectRejected(PixelforgeWebhook::post(str_replace('succeeded', 'failed', $body), $signature), 'mismatch');
});

it('rejects a body re-encoded after signing', function (): void {
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event());
    $signature = PixelforgeWebhook::signature($body);

    // Same JSON value, different bytes: only the raw body counts.
    $reencoded = json_encode(json_decode($body, true), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

    expectRejected(PixelforgeWebhook::post($reencoded, $signature), 'mismatch');
});

it('rejects a stale timestamp', function (): void {
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event());

    expectRejected(PixelforgeWebhook::post($body, PixelforgeWebhook::signature($body, now()->getTimestamp() - 301)), 'stale');
});

it('rejects a timestamp from the future beyond the tolerance', function (): void {
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event());

    expectRejected(PixelforgeWebhook::post($body, PixelforgeWebhook::signature($body, now()->getTimestamp() + 301)), 'stale');
});

it('rejects a header without v1', function (): void {
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event());

    expectRejected(PixelforgeWebhook::post($body, 't='.now()->getTimestamp()), 'signature_missing');
});

it('rejects an empty v1', function (): void {
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event());

    expectRejected(PixelforgeWebhook::post($body, 't='.now()->getTimestamp().',v1='), 'signature_missing');
});

it('rejects a non-numeric timestamp', function (string $timestamp): void {
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event());
    $valid = PixelforgeWebhook::signature($body);
    $v1 = substr($valid, strpos($valid, 'v1='));

    expectRejected(PixelforgeWebhook::post($body, "t={$timestamp},{$v1}"), 'invalid_timestamp');
})->with(['abc', '-5', '0', '12.5', '']);

it('rejects everything while no signing secret is configured', function (): void {
    config(['pixelforge.webhook_secret' => null]);
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event('ping'));

    expectRejected(PixelforgeWebhook::post($body, PixelforgeWebhook::signature($body)), 'secret_missing');
});

it('accepts a timestamp at the edge of the tolerance', function (): void {
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event('ping'));

    PixelforgeWebhook::post($body, PixelforgeWebhook::signature($body, now()->getTimestamp() - 300))
        ->assertOk()
        ->assertExactJson(['status' => 'ping']);
});

it('accepts two v1 values when the second one is valid', function (): void {
    $body = PixelforgeWebhook::body(PixelforgeWebhook::event('ping'));
    $valid = PixelforgeWebhook::signature($body);
    $t = now()->getTimestamp();

    PixelforgeWebhook::post($body, "t={$t},v1=".str_repeat('0', 64).','.substr($valid, strpos($valid, 'v1=')))
        ->assertOk()
        ->assertExactJson(['status' => 'ping']);

    expect(WebhookEvent::query()->count())->toBe(1);
});
