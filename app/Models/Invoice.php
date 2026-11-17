<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Billing\InvoiceStatus;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The invoice of one paid purchase (spec 6, D-022): numbered
 * `INV-{YYYY}-{NNNNNN}` by IssueInvoiceAction, the only writer.
 *
 * @property string $id
 * @property string $number
 * @property int $customer_id
 * @property string $purchase_id
 * @property string $currency
 * @property int $total_nanos
 * @property InvoiceStatus $status
 * @property CarbonImmutable $issued_at
 * @property string $billed_name
 * @property string $billed_email
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Customer $customer
 * @property-read Purchase $purchase
 */
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUlids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'USD',
        'status' => 'paid',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function total(): Nanodollars
    {
        return Nanodollars::fromInt($this->total_nanos);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_nanos' => 'integer',
            'status' => InvoiceStatus::class,
            'issued_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
