<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;
use OverflowException;
use Psr\Log\LoggerInterface;

/**
 * The provider cost formula, for every provider and token kind (spec 3-03 §2):
 *
 *     cost = ceil( Σ(units_k × rate_k) / 1_000_000 )
 *
 * with units as token counts and rates in integer nanodollars per 1M tokens.
 * With current rates every term divides exactly; the ceil only guards future
 * rates, and logs a warning when it rounds. The estimators, settlement and
 * spec 8 all use this one implementation.
 */
final readonly class TokenCost
{
    public const int TOKENS_PER_RATE = 1_000_000;

    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  array<string, int>  $units  token count by kind
     * @param  array<string, int>  $ratesPerMillion  nanodollars per 1M tokens by kind
     */
    public function compute(array $units, array $ratesPerMillion): Nanodollars
    {
        $total = 0;

        foreach ($units as $kind => $count) {
            if (! array_key_exists($kind, $ratesPerMillion)) {
                throw new InvalidArgumentException("No rate for token kind [{$kind}].");
            }

            $rate = $ratesPerMillion[$kind];

            if ($count < 0 || $rate < 0) {
                throw new InvalidArgumentException("Token count and rate for [{$kind}] cannot be negative.");
            }

            // Keeps total + count × rate + (TOKENS_PER_RATE − 1) within PHP_INT_MAX,
            // so the sum never silently turns into a float.
            if ($count > 0 && $rate > intdiv(PHP_INT_MAX - (self::TOKENS_PER_RATE - 1) - $total, $count)) {
                throw new OverflowException('Token cost overflows.');
            }

            $total += $count * $rate;
        }

        $remainder = $total % self::TOKENS_PER_RATE;

        if ($remainder !== 0) {
            $this->logger->warning('Token cost is not a whole number of nanodollars; rounded up.', [
                'units' => $units,
                'rates_per_million' => $ratesPerMillion,
                'remainder' => $remainder,
            ]);
        }

        return Nanodollars::fromInt(intdiv($total + self::TOKENS_PER_RATE - 1, self::TOKENS_PER_RATE));
    }
}
