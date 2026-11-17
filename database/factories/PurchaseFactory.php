<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Billing\PurchaseStatus;
use App\Models\Customer;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Purchase>
 */
final class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'reference' => 'PUR-'.Str::upper(Str::random(10)),
            'amount_nanos' => 5_000_000_000,
            'status' => PurchaseStatus::Paid,
            'paid_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => PurchaseStatus::Pending,
            'paid_at' => null,
        ]);
    }
}
