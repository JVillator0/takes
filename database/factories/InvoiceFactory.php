<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Billing\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Display fixtures only: real invoices come from IssueInvoiceAction, which
 * owns the gapless numbering. A factory number never touches the counter.
 *
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => sprintf('INV-%04d-%06d', (int) now()->utc()->year, fake()->unique()->numberBetween(900_000, 999_999)),
            'purchase_id' => Purchase::factory(),
            'customer_id' => fn (array $attributes): int => Purchase::query()->whereKey($attributes['purchase_id'])->firstOrFail()->customer_id,
            'currency' => 'USD',
            'total_nanos' => fn (array $attributes): int => Purchase::query()->whereKey($attributes['purchase_id'])->firstOrFail()->amount_nanos,
            'status' => InvoiceStatus::Paid,
            'issued_at' => now(),
            'billed_name' => fake()->name(),
            'billed_email' => fake()->safeEmail(),
        ];
    }
}
