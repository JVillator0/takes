<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\PurchaseStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A simulated credit purchase (spec 3-03 §5, D-051). ulid id (D-067).
 *
 * @property string $id
 * @property int $customer_id
 * @property string $reference
 * @property int $amount_nanos
 * @property PurchaseStatus $status
 * @property CarbonImmutable|null $paid_at
 * @property string $idempotency_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
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

    /**
     * The single `purchase` ledger entry crediting this purchase.
     *
     * @return HasOne<LedgerEntry, $this>
     */
    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(LedgerEntry::class)->where('type', LedgerEntryType::Purchase->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_nanos' => 'integer',
            'status' => PurchaseStatus::class,
            'paid_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
