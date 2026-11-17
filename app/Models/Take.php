<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Enums\Takes\TakeSource;
use App\Enums\Takes\TakeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TakeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * One generation request and its result (spec 3-02). Status changes only
 * through ApplyTakeSnapshotAction and FailTakeAction; money lives in the
 * usage record, never here.
 *
 * @property string $id
 * @property int $customer_id
 * @property string $project_id
 * @property string|null $parent_take_id
 * @property string|null $suggestion_id
 * @property TakeSource $source
 * @property string $idempotency_key
 * @property string|null $pixelforge_id
 * @property TakeStatus $status
 * @property string $prompt
 * @property TakeSize $size
 * @property TakeQuality $quality
 * @property list<string> $reference_images
 * @property FailureCode|null $failure_code
 * @property string|null $failure_message
 * @property int $submit_attempts
 * @property string|null $last_submit_error
 * @property SubmitErrorKind|null $last_submit_error_kind
 * @property CarbonImmutable|null $last_event_at
 * @property AttentionReason|null $attention_reason
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $image_path
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Take extends Model
{
    /** @use HasFactory<TakeFactory> */
    use HasFactory, HasUlids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'reference_images' => '[]',
        'submit_attempts' => 0,
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The take this one was remixed from (lineage).
     *
     * @return BelongsTo<Take, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_take_id');
    }

    /** @return HasMany<Take, $this> */
    public function remixes(): HasMany
    {
        return $this->hasMany(self::class, 'parent_take_id');
    }

    /**
     * The AI suggestion this take was created from.
     *
     * @return BelongsTo<Suggestion, $this>
     */
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(Suggestion::class);
    }

    /** @return MorphOne<UsageRecord, $this> */
    public function usageRecord(): MorphOne
    {
        return $this->morphOne(UsageRecord::class, 'billable');
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => TakeSource::class,
            'status' => TakeStatus::class,
            'size' => TakeSize::class,
            'quality' => TakeQuality::class,
            'reference_images' => 'array',
            'failure_code' => FailureCode::class,
            'submit_attempts' => 'integer',
            'last_submit_error_kind' => SubmitErrorKind::class,
            'last_event_at' => 'immutable_datetime',
            'attention_reason' => AttentionReason::class,
            'image_url_expires_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
