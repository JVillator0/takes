<?php

declare(strict_types=1);

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Queries\Takes\GetSmallestTakeQuery;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;
use App\Support\Pricing\TakeEstimator;
use Tests\Support\Billing;
use Tests\Support\TakeFlow;

/*
| The 2-01 low-balance banner: available below the smallest take's max price
| (one character, square, low, no references), priced by the one estimator.
*/

beforeEach(function (): void {
    TakeFlow::configure();
});

it('prices the smallest take and compares it with the available credits', function (): void {
    $max = app(TakeEstimator::class)->estimateForCharacters(1, TakeSize::Square, TakeQuality::Low, 0, Billing::pixelforgeRates()->toArray())->maxCharge;
    $query = app(GetSmallestTakeQuery::class);

    $at = $query->execute($max);
    $below = $query->execute($max->minus(Nanodollars::fromInt(1)));

    expect($at->max)->toBe($max->format(4, RoundingMode::Ceil))
        ->and($at->below)->toBeFalse()
        ->and($below->below)->toBeTrue()
        ->and($query->execute(Nanodollars::fromInt(-1))->below)->toBeTrue();
});
