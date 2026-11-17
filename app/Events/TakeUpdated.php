<?php

declare(strict_types=1);

namespace App\Events;

use App\DTOs\Takes\TakeLiveUpdateDTO;
use App\Models\Customer;
use App\Models\Take;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A take changed (spec 5 "Live status", W-18): pushed to the owner's private
 * channel so the open workspace refreshes the card and the balance pill.
 *
 * Never on the critical path: dispatched after the transaction commits,
 * broadcast from the queue (so the webhook answers before Reverb is called),
 * and rescued, so a queue or Reverb failure is reported, never thrown. The
 * Reverb HTTP client gives up after 2 s (config/broadcasting.php). The page
 * polls whenever the socket is not connected (D-028).
 */
final class TakeUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Take $take) {}

    /** Authorised in routes/channels.php on the `customer` guard (spec 3-01, D-032). */
    public static function channelName(int $customerId): string
    {
        return "App.Models.Customer.{$customerId}";
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->take->customer_id))];
    }

    /** Listened to as `.take.updated` by useTakeUpdates(). */
    public function broadcastAs(): string
    {
        return 'take.updated';
    }

    /**
     * Built when the queued broadcast runs, so the balance is the current one.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $customer = Customer::query()->findOrFail($this->take->customer_id);
        $available = $customer->available();
        $held = $customer->held();

        return (new TakeLiveUpdateDTO(
            takeId: $this->take->id,
            projectId: $this->take->project_id,
            status: $this->take->status->value,
            failureCode: $this->take->failure_code?->value,
            updatedAt: $this->take->updated_at?->toIso8601ZuluString(),
            available: $available->format(4),
            availableExact: $available->formatExact(),
            onHold: $held->format(4),
            onHoldExact: $held->formatExact(),
            // When the balance was read, to the microsecond: the client keeps
            // the newest balance whichever take's update carried it.
            balanceAt: now()->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
        ))->toArray();
    }
}
