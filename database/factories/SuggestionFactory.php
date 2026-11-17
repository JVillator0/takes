<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Suggestions\SuggestionStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Suggestion;
use App\Models\Take;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Suggestion>
 */
final class SuggestionFactory extends Factory
{
    protected $model = Suggestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'project_id' => fn (array $attributes) => Project::factory()->state(['customer_id' => $attributes['customer_id']]),
            'base_take_id' => fn (array $attributes) => Take::factory()->succeeded()->state([
                'customer_id' => $attributes['customer_id'],
                'project_id' => $attributes['project_id'],
            ]),
            'instruction' => 'Warmer light, portrait',
            'request_key' => (string) Str::uuid(),
            'status' => SuggestionStatus::Pending,
            'expires_at' => now()->addHour(),
        ];
    }

    public function proposed(): static
    {
        return $this->state(fn (): array => [
            'status' => SuggestionStatus::Proposed,
            'proposal' => ['size' => '1024x1536', 'prompt_append' => 'warm golden-hour light'],
            'rationale' => 'Portrait framing and warmer light.',
            'model' => 'claude-haiku-5-5',
        ]);
    }
}
