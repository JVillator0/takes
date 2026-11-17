<?php

declare(strict_types=1);

use App\Actions\Takes\ApplyTakeSnapshotAction;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\TakeStatus;
use App\Enums\Takes\TransitionOutcome;
use App\Enums\Webhooks\WebhookOutcome;
use App\Events\TakeUpdated;
use App\Jobs\ReconcileTakeJob;
use App\Models\LedgerEntry;
use App\Models\Take;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixture;
use Tests\Support\PixelforgeWebhook;
use Tests\Support\Takes;
use Tests\TestCase;

/*
| POST /webhooks/pixelforge (spec 5, W-01, W-04, W-10, W-11, W-13).
| Every delivery here is a docs fixture signed by the test helper.
*/

beforeEach(function (): void {
    PixelforgeWebhook::configure();
    Queue::fake();
    Event::fake([TakeUpdated::class]);
});

it('applies a signed success: settles, records one event and one delivery, 200', function (): void {
    $take = Takes::in('processing');
    $event = PixelforgeWebhook::forTake($take, 'succeeded', 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A');

    $response = PixelforgeWebhook::post($this, $event, attempt: 2);

    $response->assertOk()->assertExactJson(['status' => 'applied']);
    // Outside the web group: no session, no cookies, no redirect (W-01).
    expect($response->headers->getCookies())->toBe([])
        ->and($response->isRedirection())->toBeFalse();

    expect($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Settled)
        ->and(Takes::usage($take)->charge_nanos)->toBe(Takes::DOCS_CHARGE);

    $stored = WebhookEvent::query()->sole();
    expect($stored->event_id)->toBe('evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A')
        ->and($stored->type)->toBe('generation.succeeded')
        ->and($stored->generation_id)->toBe($take->pixelforge_id)
        ->and($stored->payload)->toEqualCanonicalizing($event);

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->webhook_event_id)->toBe($stored->id)
        ->and($delivery->take_id)->toBe($take->id)
        ->and($delivery->attempt)->toBe(2)
        ->and($delivery->outcome)->toBe(WebhookOutcome::Applied);

    Event::assertDispatched(TakeUpdated::class);
});

it('acknowledges a ping', function (): void {
    PixelforgeWebhook::post($this, PixelforgeFixture::json('webhook-ping'))
        ->assertOk()
        ->assertExactJson(['status' => 'ping']);

    expect(WebhookDelivery::query()->sole()->outcome)->toBe(WebhookOutcome::Ping)
        ->and(WebhookDelivery::query()->sole()->take_id)->toBeNull();
});

it('acknowledges an event type it does not act on', function (): void {
    $take = Takes::in('processing');
    $event = PixelforgeWebhook::forTake($take, 'succeeded');
    $event['type'] = 'generation.archived';

    PixelforgeWebhook::post($this, $event)->assertOk()->assertExactJson(['status' => 'ignored_type']);

    expect($take->refresh()->status)->toBe(TakeStatus::Processing)
        ->and(WebhookDelivery::query()->sole()->outcome)->toBe(WebhookOutcome::IgnoredType);
});

it('answers 400 and writes nothing for a body that is not an event', function (string $body): void {
    PixelforgeWebhook::post($this, $body)->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0);
})->with([
    'invalid JSON' => '{"id": "evt_1", "type": ',
    'a JSON string' => '"evt_1"',
    'no event id' => '{"object":"event","type":"ping","data":{}}',
    'not an evt_ id' => '{"id":"gen_1","object":"event","type":"ping","data":{}}',
    'no type' => '{"id":"evt_1","object":"event","data":{}}',
]);

it('records an event for a generation it does not know as unknown_take, 200', function (): void {
    Log::spy();

    PixelforgeWebhook::post($this, PixelforgeFixture::json('webhook-generation-succeeded'))
        ->assertOk()
        ->assertExactJson(['status' => 'unknown_take']);

    expect(WebhookDelivery::query()->sole()->outcome)->toBe(WebhookOutcome::UnknownTake)
        ->and(WebhookEvent::query()->count())->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'unknown take'))->once();
});

it('finds the take by metadata when the success arrives before the create response', function (): void {
    $take = Takes::pendingWithHold();
    expect($take->pixelforge_id)->toBeNull();

    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'succeeded'))
        ->assertOk()
        ->assertExactJson(['status' => 'applied']);

    expect($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and($take->pixelforge_id)->toBe('gen_'.$take->id);

    // The create response (202, queued) lands afterwards: a no-op.
    $late = app(ApplyTakeSnapshotAction::class)->execute($take, Takes::snapshot($take, 'queued'));

    expect($late)->toBe(TransitionOutcome::IgnoredStale)
        ->and($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(1);
});

it('does not adopt a take through metadata when it already has another generation', function (): void {
    $take = Takes::in('processing');
    $event = PixelforgeWebhook::forTake($take, 'succeeded', overrides: ['id' => 'gen_someoneelse']);

    PixelforgeWebhook::post($this, $event)->assertOk()->assertExactJson(['status' => 'unknown_take']);

    expect($take->refresh()->status)->toBe(TakeStatus::Processing);
});

it('records a malformed generation as invalid_snapshot and flags the take', function (): void {
    Log::spy();
    $take = Takes::in('processing');
    // succeeded without usage breaks the provider's own invariants (W-13).
    $event = PixelforgeWebhook::forTake($take, 'succeeded', overrides: ['usage' => null]);

    PixelforgeWebhook::post($this, $event)->assertOk()->assertExactJson(['status' => 'invalid_snapshot']);

    expect($take->refresh()->status)->toBe(TakeStatus::Processing)
        ->and($take->attention_reason)->toBe(AttentionReason::InvalidSnapshot)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open);
    Log::shouldHaveReceived('critical')->once();
});

/**
 * D-077(a): the delivery answers 5xx with nothing written, a one-off fetch is
 * queued, and that fetch (with the generation's own rates) settles exactly
 * once; Pixelforge's retry then finds the take finished.
 */
function assertRatesLessSuccessRefetchesAndSettlesOnce(TestCase $test, Take $take): void
{
    config(['pixelforge.base_url' => 'https://pixelforge.test/v1', 'pixelforge.api_key' => 'pf_test_webhook']);
    Http::preventStrayRequests();
    $generationId = 'gen_'.$take->id;
    $event = PixelforgeWebhook::forTake($take, 'succeeded', 'evt_rates_missing_00001', ['id' => $generationId, 'rates' => null]);
    Queue::fake();

    PixelforgeWebhook::post($test, $event, attempt: 1)->assertServerError();

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0)
        ->and($take->refresh()->status)->not->toBe(TakeStatus::Succeeded)
        ->and($take->attention_reason)->toBeNull()
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(0);

    $fetch = null;
    Queue::assertPushed(ReconcileTakeJob::class, function (ReconcileTakeJob $job) use ($take, $generationId, &$fetch): bool {
        $fetch = $job;

        return $job->takeId === $take->id && ! $job->continuesChain && $job->generationId === $generationId && $job->delay === null;
    });

    // The one-off fetch: GET /v1/generations/{id} returns it with its rates.
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::generation('succeeded', [
        'id' => $generationId,
        'metadata' => ['app_take_id' => $take->id],
    ]))]);
    Queue::fake();
    app()->call([$fetch, 'handle']);

    expect($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and(Takes::usage($take)->charge_nanos)->toBe(Takes::DOCS_CHARGE);
    Queue::assertNotPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->continuesChain && $job->attempt > 0);

    // Pixelforge retries the delivery: recorded, nothing settles again.
    PixelforgeWebhook::post($test, $event, attempt: 2)->assertOk()->assertExactJson(['status' => 'ignored_same_state']);

    expect(LedgerEntry::query()->where('type', 'charge')->count())->toBe(1)
        ->and(WebhookDelivery::query()->sole()->attempt)->toBe(2);
}

it('answers 5xx for a success without rates, then settles once from the fetched generation', function (): void {
    $take = Takes::in('processing');

    assertRatesLessSuccessRefetchesAndSettlesOnce($this, $take);
});

it('refetches by the event generation id when the take was found by metadata', function (): void {
    $take = Takes::pendingWithHold();
    expect($take->pixelforge_id)->toBeNull();

    assertRatesLessSuccessRefetchesAndSettlesOnce($this, $take);

    expect($take->refresh()->pixelforge_id)->toBe('gen_'.$take->id);
});
