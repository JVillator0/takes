<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per distinct Pixelforge event, unique by event_id (D-027). Holds no
 * outcome: outcomes live on each delivery.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $generation_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $received_at
 */
final class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
        ];
    }
}
