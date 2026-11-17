<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Support\Money\Nanodollars;
use App\Support\Pixelforge\Payload;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One UTC day of GET /v1/usage (billing day = the day a generation
 * completed). `cost_usd` is an exact nine-decimal string.
 *
 * @phpstan-type UsageDayPayload array{
 *     date: string,
 *     generations: int,
 *     input_text_tokens: int,
 *     input_image_tokens: int,
 *     output_tokens: int,
 *     reasoning_tokens: int,
 *     cost_usd: string,
 * }
 */
final readonly class UsageDayDTO
{
    public function __construct(
        public CarbonImmutable $date,
        public int $generations,
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public Nanodollars $cost,
    ) {}

    /**
     * @param  array<mixed>  $data  a {@see UsageDayPayload}
     */
    public static function fromArray(array $data): self
    {
        try {
            $cost = Nanodollars::fromDecimalString(Payload::string($data, 'cost_usd'));
        } catch (InvalidArgumentException) {
            throw Payload::invalid('cost_usd', 'a USD decimal string');
        }

        if ($cost->isNegative()) {
            throw Payload::invalid('cost_usd', 'a non-negative amount');
        }

        return new self(
            date: Payload::date($data, 'date'),
            generations: Payload::count($data, 'generations'),
            inputTextTokens: Payload::count($data, 'input_text_tokens'),
            inputImageTokens: Payload::count($data, 'input_image_tokens'),
            outputTokens: Payload::count($data, 'output_tokens'),
            reasoningTokens: Payload::count($data, 'reasoning_tokens'),
            cost: $cost,
        );
    }
}
