<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
final class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $generationId = 'gen_'.Str::lower(Str::random(24));

        return [
            'event_id' => 'evt_'.Str::lower(Str::random(24)),
            'type' => 'generation.succeeded',
            'generation_id' => $generationId,
            'payload' => ['type' => 'generation.succeeded', 'data' => ['object' => ['id' => $generationId, 'status' => 'succeeded']]],
            'received_at' => now(),
        ];
    }
}
