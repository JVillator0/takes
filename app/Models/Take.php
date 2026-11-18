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
 * usage record, never here. Never hard-deleted: the usage record points here
 * through a morph pair with no FK, so a delete would orphan money rows.
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
     * Whether a submit attempt may have reached Pixelforge without a definite
     * answer (D-025, D-082): an ambiguous outcome, or an attempt that was
     * counted but never recorded (the worker died during the call;
     * RecordSubmitAttemptAction resets the kind to null before every call).
     * Both columns change only while the take is pending, so on a failed take
     * this is a fact frozen at failure time. SubmitTakeJob uses it to pick
     * submit_timeout over provider_busy; failureMayHideAGeneration() to tell an
     * orphan success from a conflict after an internal failure.
     * A definite rejection of a counted attempt (e.g. a 401 that fails the
     * take internal) also leaves kind null with attempts > 0 and reads as in
     * doubt: on purpose, the conservative side (D-082), since a wrongly
     * flagged orphan costs an admin look while a missed one hides cost.
     */
    public function submitWasInDoubt(): bool
    {
        return $this->last_submit_error_kind === SubmitErrorKind::Ambiguous
            || ($this->last_submit_error_kind === null && $this->submit_attempts > 0);
    }

    /**
     * Whether this take's local failure may have hidden a generation
     * Pixelforge ran, so a late success is an orphan, not a conflict
     * (spec 5 matrix, M-14). submit_timeout: the ambiguous submit window
     * expired (D-025, D-049). internal on a take Pixelforge had accepted: we
     * failed it free, e.g. on a revoked key (D-062), while it kept running
     * (D-077); "accepted" is the pixelforge_id it had when it failed, frozen
     * because a failed take adopts a generation id only as an orphan.
     * internal while its submit was in doubt (D-082). Every other local
     * code, and internal after capacity answers only or before any attempt,
     * means nothing was created. Used by ApplyTakeSnapshotAction and
     * ReconcileTakeAction, so both classify the same way.
     */
    public function failureMayHideAGeneration(): bool
    {
        return $this->status === TakeStatus::Failed
            && ($this->failure_code === FailureCode::SubmitTimeout
                || ($this->failure_code === FailureCode::Internal
                    && ($this->pixelforge_id !== null || $this->submitWasInDoubt())));
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
