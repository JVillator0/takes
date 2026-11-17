<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Takes\FailureCode;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Enums\Takes\TakeSource;
use App\Enums\Takes\TakeStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Take;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Take>
 */
final class TakeFactory extends Factory
{
    protected $model = Take::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            // The project always belongs to the same customer.
            'project_id' => fn (array $attributes) => Project::factory()->state(['customer_id' => $attributes['customer_id']]),
            'source' => TakeSource::Composer,
            'idempotency_key' => (string) Str::uuid(),
            'status' => TakeStatus::Pending,
            'prompt' => fake()->sentence(12),
            'size' => TakeSize::Square,
            'quality' => TakeQuality::Medium,
            'reference_images' => [],
        ];
    }

    public function queued(): static
    {
        return $this->accepted(TakeStatus::Queued);
    }

    public function processing(): static
    {
        return $this->accepted(TakeStatus::Processing)
            ->state(fn (): array => ['started_at' => now()]);
    }

    public function succeeded(): static
    {
        return $this->accepted(TakeStatus::Succeeded)->state(fn (): array => [
            'started_at' => now()->subSeconds(20),
            'completed_at' => now(),
            'image_url' => 'https://pixelforge.example/images/'.Str::lower(Str::random(16)).'.png',
            'image_url_expires_at' => now()->addDay(),
        ]);
    }

    public function failed(FailureCode $code = FailureCode::GenerationFailed): static
    {
        return $this->state(fn (): array => [
            'status' => TakeStatus::Failed,
            'failure_code' => $code,
            'failure_message' => 'The generation failed.',
            'completed_at' => now(),
            'last_event_at' => now(),
        ]);
    }

    /** A remix of $parent, in the same project. */
    public function remixOf(Take $parent): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $parent->customer_id,
            'project_id' => $parent->project_id,
            'parent_take_id' => $parent->id,
            'source' => TakeSource::Remix,
        ]);
    }

    /** Accepted by Pixelforge: it has a generation id. */
    private function accepted(TakeStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'pixelforge_id' => 'gen_'.Str::lower(Str::random(24)),
            'submit_attempts' => 1,
            'submitted_at' => now()->subMinute(),
            'last_event_at' => now(),
        ]);
    }
}
