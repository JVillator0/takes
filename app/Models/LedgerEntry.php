<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\LedgerEntryType;
use App\Exceptions\Billing\ImmutableLedgerEntryException;
use Carbon\CarbonImmutable;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only real-money movement (spec 3-03 §5). Holds are never ledger rows
 * (D-024). RecordLedgerEntryAction is the only writer; a correction is a new
 * `adjustment`. Saving or deleting a model instance throws
 * ImmutableLedgerEntryException; every other path (query builder, raw SQL)
 * is refused by the `ledger_entries_append_only` database trigger.
 *
 * @property int $id
 * @property int $customer_id
 * @property LedgerEntryType $type
 * @property int $amount_nanos
 * @property string|null $usage_record_id
 * @property string|null $purchase_id
 * @property int|null $admin_id
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 */
final class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    /** Only `created_at`: an entry is never updated. */
    public const null UPDATED_AT = null;

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<UsageRecord, $this> */
    public function usageRecord(): BelongsTo
    {
        return $this->belongsTo(UsageRecord::class);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * The admin who made an adjustment.
     *
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw ImmutableLedgerEntryException::forUpdate();
        });

        self::deleting(function (): never {
            throw ImmutableLedgerEntryException::forDelete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_nanos' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
