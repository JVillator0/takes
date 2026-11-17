<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Webhooks\WebhookOutcome;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 */
final class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => 'evt_'.Str::lower(Str::random(24)),
            'webhook_event_id' => fn (array $attributes) => WebhookEvent::factory()->state(['event_id' => $attributes['event_id']]),
            'type' => 'generation.succeeded',
            'attempt' => 1,
            'outcome' => WebhookOutcome::Applied,
            'received_at' => now(),
        ];
    }

    public function duplicate(): static
    {
        return $this->state(fn (): array => [
            'attempt' => 2,
            'outcome' => WebhookOutcome::Duplicate,
        ]);
    }
}
