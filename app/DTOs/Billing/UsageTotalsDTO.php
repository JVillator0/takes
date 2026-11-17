<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\Nanodollars;

/**
 * One side of a reconciliation day (A04): generations, the four token sums and the cost. Comparing every field
 * (not only cost) catches offsetting errors (D-034).
 */
final readonly class UsageTotalsDTO
{
    public function __construct(
        public int $generations,
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public Nanodollars $cost,
    ) {}

    /**
     * @param  array{generations: int|string, input_text_tokens: int|string, input_image_tokens: int|string, output_tokens: int|string, reasoning_tokens: int|string, cost_nanos: int|string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['generations'],
            (int) $data['input_text_tokens'],
            (int) $data['input_image_tokens'],
            (int) $data['output_tokens'],
            (int) $data['reasoning_tokens'],
            Nanodollars::fromInt($data['cost_nanos']),
        );
    }

    public static function zero(): self
    {
        return new self(0, 0, 0, 0, 0, Nanodollars::zero());
    }

    /**
     * @return array{generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_nanos: int}
     */
    public function toArray(): array
    {
        return [
            'generations' => $this->generations,
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'cost_nanos' => $this->cost->toInt(),
        ];
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /**
     * Fields that differ, ours − theirs, e.g. ['output_tokens' => 1100].
     *
     * @return array<string, int>
     */
    public function differences(self $theirs): array
    {
        $ours = $this->toArray();
        $other = $theirs->toArray();

        return array_filter(
            array_map(fn (string $field): int => $ours[$field] - $other[$field], array_combine(array_keys($ours), array_keys($ours))),
            fn (int $difference): bool => $difference !== 0,
        );
    }

    public function plus(self $other): self
    {
        return new self(
            $this->generations + $other->generations,
            $this->inputTextTokens + $other->inputTextTokens,
            $this->inputImageTokens + $other->inputImageTokens,
            $this->outputTokens + $other->outputTokens,
            $this->reasoningTokens + $other->reasoningTokens,
            $this->cost->plus($other->cost),
        );
    }
}
