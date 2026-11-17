<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\Nanodollars;

/**
 * One row of the A04 **Find takes** drill-down: a Pixelforge generation completed on the mismatching day, the
 * take it belongs to (when we have one), Pixelforge's cost (tokens × its rates) and ours (the settled usage).
 * Cached as a plain array (toArray / fromArray), never as an object.
 */
final readonly class ReconciliationGenerationDTO
{
    public function __construct(
        public string $generationId,
        public ?string $takeId,
        public ?Nanodollars $theirCost,
        public ?Nanodollars $ourCost,
    ) {}

    /**
     * @param  array{generation_id: string, take_id: string|null, their_cost_nanos: int|null, our_cost_nanos: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['generation_id'],
            $data['take_id'],
            $data['their_cost_nanos'] === null ? null : Nanodollars::fromInt($data['their_cost_nanos']),
            $data['our_cost_nanos'] === null ? null : Nanodollars::fromInt($data['our_cost_nanos']),
        );
    }

    /**
     * @return array{generation_id: string, take_id: string|null, their_cost_nanos: int|null, our_cost_nanos: int|null}
     */
    public function toArray(): array
    {
        return [
            'generation_id' => $this->generationId,
            'take_id' => $this->takeId,
            'their_cost_nanos' => $this->theirCost?->toInt(),
            'our_cost_nanos' => $this->ourCost?->toInt(),
        ];
    }
}
