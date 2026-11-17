<?php

declare(strict_types=1);

use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Enums\Webhooks\WebhookOutcome;
use App\Events\TakeUpdated;
use App\Models\LedgerEntry;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeWebhook;
use Tests\Support\Takes;

/*
| W-05, W-06, W-07, M-12 (D-027): an event is recorded once, every delivery is
| recorded, and a duplicate changes nothing. Pixelforge delivers some events
| twice on purpose (`[duplicate]` in the prompt makes it deliver every event
| twice), and retries resend the same event id with a higher attempt.
*/

beforeEach(function (): void {
    PixelforgeWebhook::configure();
    Queue::fake();
    Event::fake([TakeUpdated::class]);
});

it('settles once when the same success is delivered twice', function (): void {
    $take = Takes::in('processing');
    $event = PixelforgeWebhook::forTake($take, 'succeeded', 'evt_dup_success_000001');
    Event::fake([TakeUpdated::class]);

    PixelforgeWebhook::post($this, $event, attempt: 1)->assertOk()->assertExactJson(['status' => 'applied']);
    $customer = $take->customer()->firstOrFail();

    PixelforgeWebhook::post($this, $event, attempt: 2)->assertOk()->assertExactJson(['status' => 'duplicate']);

    expect(WebhookEvent::query()->count())->toBe(1);

    $deliveries = WebhookDelivery::query()->orderBy('id')->get();
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('outcome')->all())->toBe([WebhookOutcome::Applied, WebhookOutcome::Duplicate])
        ->and($deliveries->pluck('attempt')->all())->toBe([1, 2])
        ->and($deliveries->pluck('take_id')->unique()->all())->toBe([$take->id])
        ->and($deliveries->pluck('webhook_event_id')->unique()->count())->toBe(1);

    // One settlement: one charge, balance and usage untouched by the duplicate.
    expect(LedgerEntry::query()->where('type', 'charge')->count())->toBe(1)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Settled)
        ->and($take->customer()->firstOrFail()->balance_nanos)->toBe($customer->balance_nanos);
    Event::assertDispatchedTimes(TakeUpdated::class, 1);
});

it('records every event of a [duplicate] generation once and every delivery twice', function (): void {
    $take = Takes::pendingWithHold(['prompt' => 'A lighthouse at dawn [duplicate]']);
    $take->forceFill(['pixelforge_id' => 'gen_'.$take->id])->save();

    $processing = PixelforgeWebhook::forTake($take, 'processing', 'evt_dup_processing_0001');
    $succeeded = PixelforgeWebhook::forTake($take, 'succeeded', 'evt_dup_succeeded_00001');

    foreach ([$processing, $processing, $succeeded, $succeeded] as $event) {
        PixelforgeWebhook::post($this, $event)->assertOk();
    }

    expect(WebhookEvent::query()->count())->toBe(2)
        ->and(WebhookDelivery::query()->orderBy('id')->pluck('outcome')->all())->toBe([
            WebhookOutcome::Applied, WebhookOutcome::Duplicate,
            WebhookOutcome::Applied, WebhookOutcome::Duplicate,
        ])
        ->and($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(1);
});

it('treats a duplicate of an unknown event type as a duplicate', function (): void {
    $event = PixelforgeWebhook::event('generation.archived', eventId: 'evt_dup_unknown_type_01');

    PixelforgeWebhook::post($this, $event)->assertExactJson(['status' => 'ignored_type']);
    PixelforgeWebhook::post($this, $event)->assertExactJson(['status' => 'duplicate']);

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->count())->toBe(2);
});
