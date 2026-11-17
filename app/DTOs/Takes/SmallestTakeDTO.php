<?php

declare(strict_types=1);

namespace App\DTOs\Takes;

/**
 * The 2-01 low-balance banner: the smallest possible take's max price (4
 * decimals, rounded up like every max) and whether the customer's available
 * credits are below it. Mirrors `SmallestTake` in resources/js/types/takes.ts.
 */
final readonly class SmallestTakeDTO
{
    public function __construct(
        public string $max,
        public bool $below,
    ) {}

    /**
     * @param  array{max: string, below: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['max'], $data['below']);
    }

    /**
     * @return array{max: string, below: bool}
     */
    public function toArray(): array
    {
        return ['max' => $this->max, 'below' => $this->below];
    }
}
