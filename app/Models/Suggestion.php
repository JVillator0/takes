<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Suggestions\SuggestionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SuggestionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * One "Describe a change" AI call proposing a parameter diff (spec 8). Billed
 * through the same usage records and ledger as takes (D-009). Never
 * hard-deleted, for the same reason as Take (morph usage record, no FK).
 *
 * @property string $id
 * @property int $customer_id
 * @property string $project_id
 * @property string $base_take_id
 * @property string $instruction
 * @property string $request_key
 * @property array<string, mixed>|null $proposal
 * @property string|null $rationale
 * @property string|null $model
 * @property SuggestionStatus $status
 * @property string|null $failure_reason
 * @property string|null $resulting_take_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Suggestion extends Model
{
    /** @use HasFactory<SuggestionFactory> */
    use HasFactory, HasUlids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
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
     * The take the instruction applies to.
     *
     * @return BelongsTo<Take, $this>
     */
    public function baseTake(): BelongsTo
    {
        return $this->belongsTo(Take::class, 'base_take_id');
    }

    /**
     * The take created from the proposal.
     *
     * @return BelongsTo<Take, $this>
     */
    public function resultingTake(): BelongsTo
    {
        return $this->belongsTo(Take::class, 'resulting_take_id');
    }

    /**
     * The take pointing back at this suggestion (`takes.suggestion_id`, unique).
     *
     * @return HasOne<Take, $this>
     */
    public function take(): HasOne
    {
        return $this->hasOne(Take::class);
    }

    /** @return MorphOne<UsageRecord, $this> */
    public function usageRecord(): MorphOne
    {
        return $this->morphOne(UsageRecord::class, 'billable');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposal' => 'array',
            'status' => SuggestionStatus::class,
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
