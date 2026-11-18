<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use Carbon\CarbonImmutable;
use Database\Factories\UsageRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One billable provider call (spec 3-03 §5). While open, max_charge_nanos is
 * the customer's hold (D-024); Open/Settle/Void actions own every change.
 *
 * @property string $id
 * @property int $customer_id
 * @property Provider $provider
 * @property string $billable_type
 * @property string $billable_id
 * @property string|null $model
 * @property UsageStatus $status
 * @property int $min_cost_nanos
 * @property int $max_cost_nanos
 * @property int $min_charge_nanos
 * @property int $max_charge_nanos
 * @property array<string, int>|null $units
 * @property array<string, int> $rates Nanodollars per 1M tokens by kind (spec 3-03 §2)
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property list<string> $flags
 * @property int|null $provider_cost_nanos
 * @property int|null $charge_nanos
 * @property int|null $absorbed_cost_nanos Provider cost of a voided usage the provider still billed (spec 8 budget); admin-only
 * @property int $markup_bps
 * @property string|null $external_id
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $billing_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UsageRecord extends Model
{
    /** @use HasFactory<UsageRecordFactory> */
    use HasFactory, HasUlids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'flags' => '[]',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The take or suggestion this call was made for (morph map aliases).
     *
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The single charge entry once settled.
     *
     * @return HasOne<LedgerEntry, $this>
     */
    public function chargeEntry(): HasOne
    {
        return $this->hasOne(LedgerEntry::class)->where('type', LedgerEntryType::Charge->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => UsageStatus::class,
            'min_cost_nanos' => 'integer',
            'max_cost_nanos' => 'integer',
            'min_charge_nanos' => 'integer',
            'max_charge_nanos' => 'integer',
            'units' => 'array',
            'rates' => 'array',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'flags' => 'array',
            'provider_cost_nanos' => 'integer',
            'charge_nanos' => 'integer',
            'absorbed_cost_nanos' => 'integer',
            'markup_bps' => 'integer',
            'completed_at' => 'immutable_datetime',
            'billing_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
