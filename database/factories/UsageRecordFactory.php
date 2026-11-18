<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an open Pixelforge usage for a take, sized like the docs'
 * worked example (max cost 373,875,000 nanodollars). Factories write rows
 * only; they do not update the customer's cached balance or hold.
 *
 * @extends Factory<UsageRecord>
 */
final class UsageRecordFactory extends Factory
{
    protected $model = UsageRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'provider' => Provider::Pixelforge,
            'billable_type' => 'take',
            'billable_id' => fn (array $attributes) => Take::factory()->state(['customer_id' => $attributes['customer_id']]),
            'status' => UsageStatus::Open,
            'min_cost_nanos' => 100_000_000,
            'max_cost_nanos' => 373_875_000,
            'min_charge_nanos' => 125_000_000,
            'max_charge_nanos' => 467_343_750,
            // Nanodollars per 1M tokens by kind (spec 3-03 §2, demo dataset).
            'rates' => [
                'input_text' => 5_000_000_000,
                'input_image' => 10_000_000_000,
                'output' => 40_000_000_000,
                'reasoning' => 12_500_000_000,
            ],
            'flags' => [],
            'markup_bps' => Nanodollars::DEFAULT_MARKUP_BPS,
        ];
    }

    public function settled(int $providerCostNanos = 300_000_000): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UsageStatus::Settled,
            'provider_cost_nanos' => $providerCostNanos,
            'charge_nanos' => Nanodollars::fromInt($providerCostNanos)->withMarkup((int) $attributes['markup_bps'])->toInt(),
            'completed_at' => now(),
            'billing_date' => now()->utc()->toDateString(),
        ]);
    }

    /** @param  int|null  $absorbedCostNanos  provider cost the provider still billed (an unusable AI call) */
    public function voided(?int $absorbedCostNanos = null): static
    {
        return $this->state(fn (): array => [
            'status' => UsageStatus::Voided,
            'provider_cost_nanos' => 0,
            'charge_nanos' => 0,
            'absorbed_cost_nanos' => $absorbedCostNanos,
            'completed_at' => $absorbedCostNanos === null ? null : now(),
        ]);
    }

    /** An Anthropic "Describe a change" call (spec 8). */
    public function forSuggestion(): static
    {
        return $this->state(fn (): array => [
            'provider' => Provider::Anthropic,
            'model' => 'claude-haiku-5-5',
            'billable_type' => 'suggestion',
            'billable_id' => fn (array $attributes) => Suggestion::factory()->state(['customer_id' => $attributes['customer_id']]),
            'min_cost_nanos' => 50_000,
            'max_cost_nanos' => 400_000,
            'min_charge_nanos' => 62_500,
            'max_charge_nanos' => 500_000,
            // Haiku 5.5: $0.10 / $0.50 per 1M tokens, in nanodollars.
            'rates' => ['input_text' => 100_000_000, 'output' => 500_000_000],
        ]);
    }
}
