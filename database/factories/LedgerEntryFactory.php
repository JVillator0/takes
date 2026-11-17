<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Billing\LedgerEntryType;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\UsageRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factories write rows only; they do not update customers.balance_nanos.
 * In app code RecordLedgerEntryAction is the only writer (spec 3-03 §6).
 *
 * @extends Factory<LedgerEntry>
 */
final class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => LedgerEntryType::PromoGrant,
            'amount_nanos' => 1_000_000_000,
        ];
    }

    /** The $1.00 welcome credit, unique per customer (D-019). */
    public function welcome(): static
    {
        return $this->state(fn (): array => [
            'type' => LedgerEntryType::PromoGrant,
            'amount_nanos' => 1_000_000_000,
            'reason' => 'welcome',
        ]);
    }

    public function purchase(): static
    {
        return $this->state(fn (): array => [
            'type' => LedgerEntryType::Purchase,
            'amount_nanos' => 5_000_000_000,
            'purchase_id' => fn (array $attributes) => Purchase::factory()->state([
                'customer_id' => $attributes['customer_id'],
                'amount_nanos' => $attributes['amount_nanos'],
            ]),
        ]);
    }

    public function charge(): static
    {
        return $this->state(fn (): array => [
            'type' => LedgerEntryType::Charge,
            'amount_nanos' => -375_000_000,
            'usage_record_id' => fn (array $attributes) => UsageRecord::factory()
                ->settled(300_000_000)
                ->state(['customer_id' => $attributes['customer_id']]),
        ]);
    }

    public function adjustment(int $amountNanos, string $reason = 'Goodwill credit'): static
    {
        return $this->state(fn (): array => [
            'type' => LedgerEntryType::Adjustment,
            'amount_nanos' => $amountNanos,
            'reason' => $reason,
        ]);
    }
}
