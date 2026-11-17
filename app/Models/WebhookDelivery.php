<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Webhooks\WebhookOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: every verified delivery with its attempt and outcome, including
 * duplicates (D-027). The only table that holds the outcome.
 *
 * @property int $id
 * @property string $event_id
 * @property int|null $webhook_event_id
 * @property string|null $take_id
 * @property string $type
 * @property int $attempt
 * @property WebhookOutcome $outcome
 * @property CarbonImmutable $received_at
 */
final class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<WebhookEvent, $this> */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    /** @return BelongsTo<Take, $this> */
    public function take(): BelongsTo
    {
        return $this->belongsTo(Take::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'outcome' => WebhookOutcome::class,
            'received_at' => 'immutable_datetime',
        ];
    }
}
