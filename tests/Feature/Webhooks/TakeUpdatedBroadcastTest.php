<?php

declare(strict_types=1);

use App\Enums\Takes\TakeStatus;
use App\Events\TakeUpdated;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeWebhook;
use Tests\Support\Takes;

/*
| TakeUpdated (spec 5 "Live status", W-18, W-19): queued, after commit,
| rescued, on the owner's private channel, with an explicit name.
*/

it('broadcasts after commit, from the queue, and never throws', function (): void {
    expect(new ReflectionClass(TakeUpdated::class))
        ->implementsInterface(ShouldBroadcast::class)->toBeTrue()
        ->implementsInterface(ShouldDispatchAfterCommit::class)->toBeTrue()
        ->implementsInterface(ShouldRescue::class)->toBeTrue()
        ->and(config('broadcasting.connections.reverb.client_options.timeout'))->toBe(2);
});

it('goes to the owner channel as take.updated', function (): void {
    $take = Takes::in('processing');
    $event = new TakeUpdated($take);

    expect($event->broadcastAs())->toBe('take.updated')
        ->and($event->broadcastOn())->toEqual([new PrivateChannel("App.Models.Customer.{$take->customer_id}")]);
});

it('carries the take status and the balance pill as server strings', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-17T12:00:00.123456Z'));
    $take = Takes::in('processing');
    $payload = (new TakeUpdated($take))->broadcastWith();

    expect($payload)->toBe([
        'take' => [
            'id' => $take->id,
            'projectId' => $take->project_id,
            'status' => 'processing',
            'failureCode' => null,
            'updatedAt' => $take->updated_at?->toIso8601ZuluString(),
        ],
        'balance' => [
            // $10.00 funded, 467,343,750 on hold: available rounds down, on hold up (D-021).
            'available' => '$9.5326',
            'availableExact' => '$9.53265625',
            'onHold' => '$0.4674',
            'onHoldExact' => '$0.46734375',
        ],
        'balanceAt' => '2026-11-17T12:00:00.123456Z',
    ]);
});

it('is queued only once the transaction commits', function (): void {
    $take = Takes::in('processing');
    Queue::fake();

    DB::transaction(function () use ($take): void {
        TakeUpdated::dispatch($take);

        Queue::assertNotPushed(BroadcastEvent::class);
    });

    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof TakeUpdated);
});

it('answers the webhook 200 even when broadcasting blows up', function (): void {
    PixelforgeWebhook::configure();
    Broadcast::extend('exploding', fn () => new class extends Broadcaster
    {
        public function auth($request): mixed
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            return null;
        }

        /**
         * @param  array<int, mixed>  $channels
         * @param  array<string, mixed>  $payload
         */
        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new BroadcastException('Reverb is down.');
        }
    });
    config([
        'broadcasting.connections.exploding' => ['driver' => 'exploding'],
        'broadcasting.default' => 'exploding',
    ]);
    $take = Takes::in('processing');

    // QUEUE_CONNECTION=sync: the broadcast runs inside the request here, the
    // worst case. In production it runs later on a queue worker.
    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'succeeded'))
        ->assertOk()
        ->assertExactJson(['status' => 'applied']);

    expect($take->refresh()->status)->toBe(TakeStatus::Succeeded);
});
