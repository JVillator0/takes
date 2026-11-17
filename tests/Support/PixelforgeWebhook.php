<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Take;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed Pixelforge webhook deliveries for tests (spec 5, W-17): pool
 * worktrees never receive real webhooks, so every webhook test posts a
 * fixture signed here the way Pixelforge signs it (docs, "Verifying
 * signatures"): `Pixelforge-Signature: t=<unix>,v1=<hex HMAC-SHA256 of
 * "<t>.<raw body>">`. The HMAC is computed here independently of
 * App\Support\Pixelforge\WebhookSignature, so a bug there cannot sign its
 * own way past the tests.
 *
 * Fixtures: webhook-generation-succeeded is the documented event envelope
 * (docs, "Webhooks") around the documented succeeded generation; other
 * statuses swap in the documented generation objects. webhook-ping is the
 * envelope with type `ping` (the docs show no ping body).
 */
final class PixelforgeWebhook
{
    public const string SECRET = 'whsec_test_0123456789abcdef';

    public const string URL = '/webhooks/pixelforge';

    public static function configure(): void
    {
        config([
            'pixelforge.webhook_secret' => self::SECRET,
            'pixelforge.webhook_tolerance_seconds' => 300,
        ]);
    }

    /**
     * The documented event envelope with another type, object or event id.
     *
     * @param  array<string, mixed>|null  $object  data.object; null keeps the fixture's
     * @return array<string, mixed>
     */
    public static function event(string $type = 'generation.succeeded', ?array $object = null, ?string $eventId = null): array
    {
        $event = PixelforgeFixture::json('webhook-generation-succeeded');
        $event['type'] = $type;
        $event['id'] = $eventId ?? $event['id'];

        if ($object !== null) {
            $event['data'] = ['object' => $object];
        }

        return $event;
    }

    /**
     * A `generation.<status>` event for this take: the documented generation
     * object at that status, with the take's generation id and the metadata
     * our create request sends.
     *
     * @param  array<string, mixed>  $overrides  fields of data.object
     * @return array<string, mixed>
     */
    public static function forTake(Take $take, string $status, ?string $eventId = null, array $overrides = []): array
    {
        $fixture = $status === 'processing' ? 'queued' : $status;
        $object = PixelforgeFixture::generation($fixture, [
            'id' => $take->pixelforge_id ?? 'gen_'.strtolower($take->id),
            'status' => $status,
            'metadata' => ['app_take_id' => $take->id, 'app_customer_id' => (string) $take->customer_id],
            ...($status === 'processing' ? ['started_at' => '2026-11-04T14:03:13Z'] : []),
            ...$overrides,
        ]);

        return self::event("generation.{$status}", $object, $eventId ?? 'evt_'.strtolower(bin2hex(random_bytes(12))));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function body(array $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function signature(string $rawBody, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp ??= now()->getTimestamp();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    /**
     * POSTs the raw body. `$signature` null sends no signature header.
     *
     * @param  array<string, mixed>|string  $event  an event array, or a raw body sent as is
     * @return TestResponse<Response>
     */
    public static function post(array|string $event, ?string $signature = 'sign', int $attempt = 1): TestResponse
    {
        $body = is_string($event) ? $event : self::body($event);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'Pixelforge-Webhooks/1.0',
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => (string) $attempt,
        ];

        if (! is_string($event)) {
            $server['HTTP_PIXELFORGE_EVENT_ID'] = (string) ($event['id'] ?? '');
            $server['HTTP_PIXELFORGE_EVENT_TYPE'] = (string) ($event['type'] ?? '');
        }

        if ($signature !== null) {
            $server['HTTP_PIXELFORGE_SIGNATURE'] = $signature === 'sign' ? self::signature($body) : $signature;
        }

        return test()->call('POST', self::URL, server: $server, content: $body);
    }
}
