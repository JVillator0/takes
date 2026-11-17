<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Billing\UsageKind;
use InvalidArgumentException;
use OverflowException;

/**
 * Provider cost of one call, for any provider (spec 3-03 §2):
 *
 *     cost = ceil( Σ(units_k × rate_k) / 1_000_000 )
 *
 * with rate_k in nanodollars per 1M tokens. Integer math only. With current
 * rates every term divides exactly; `roundedUp` reports when the ceil was
 * needed so the caller can log it (it only guards future rates).
 */
final readonly class ProviderCost
{
    public const int TOKENS_PER_RATE_UNIT = 1_000_000;

    private function __construct(
        public Nanodollars $cost,
        public bool $roundedUp,
    ) {}

    public static function calculate(UsageUnitsDTO $units, RatesSnapshotDTO $rates): self
    {
        $numerator = 0;

        foreach (UsageKind::cases() as $kind) {
            $tokens = $units->tokens($kind) ?? 0;

            if ($tokens === 0) {
                continue;
            }

            $rate = $rates->rate($kind)
                ?? throw new InvalidArgumentException("No rate for usage kind [{$kind->value}]; the call cannot be priced.");

            if ($rate > 0 && $tokens > intdiv(PHP_INT_MAX, $rate)) {
                throw new OverflowException("Cost of [{$kind->value}] overflows.");
            }

            $term = $tokens * $rate;

            if ($numerator > PHP_INT_MAX - $term) {
                throw new OverflowException('Provider cost overflows.');
            }

            $numerator += $term;
        }

        $remainder = $numerator % self::TOKENS_PER_RATE_UNIT;
        $cost = intdiv($numerator, self::TOKENS_PER_RATE_UNIT) + ($remainder > 0 ? 1 : 0);

        return new self(Nanodollars::fromInt($cost), $remainder > 0);
    }
}
