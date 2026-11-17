<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\DTOs\Takes\EstimateTakeDTO;
use App\DTOs\Takes\SmallestTakeDTO;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;

/**
 * The smallest take anyone can generate (one character, square, low, no
 * references) priced by the same estimator as the composer, against the
 * customer's available credits: the 2-01 low-balance banner ("below the
 * smallest take ($x)").
 */
final class GetSmallestTakeQuery
{
    public function __construct(private readonly EstimateTakeQuery $estimateTake) {}

    public function execute(Nanodollars $available): SmallestTakeDTO
    {
        $max = $this->estimateTake
            ->execute(new EstimateTakeDTO(1, TakeSize::Square, TakeQuality::Low, 0))
            ->estimate
            ->maxCharge;

        return new SmallestTakeDTO(
            max: $max->format(4, RoundingMode::Ceil),
            below: $available->lessThan($max),
        );
    }
}
