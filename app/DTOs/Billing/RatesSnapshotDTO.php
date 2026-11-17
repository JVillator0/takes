<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\Billing\UsageKind;
use InvalidArgumentException;

/**
 * A provider's rates at one point in time: integer nanodollars per 1M tokens,
 * by kind (spec 3-03 §2). Pixelforge output at $40.00 / 1M is 40_000_000_000.
 * Stored as-is in `usage_records.rates` (jsonb integers): at open from the
 * pricing used for the estimate, overwritten at settle with the rates the
 * provider actually charged (D-055).
 */
final readonly class RatesSnapshotDTO
{
    /**
     * @param  array<string, int>  $nanosPerMillion  keyed by UsageKind value
     */
    public function __construct(public array $nanosPerMillion)
    {
        foreach ($nanosPerMillion as $kind => $rate) {
            if (UsageKind::tryFrom((string) $kind) === null) {
                throw new InvalidArgumentException("Unknown usage kind [{$kind}].");
            }

            if ($rate < 0) {
                throw new InvalidArgumentException("Rate for [{$kind}] cannot be negative.");
            }
        }
    }

    /**
     * Rebuilds a snapshot read back from `usage_records.rates`.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        $rates = [];

        foreach ($stored as $kind => $rate) {
            if (! is_int($rate)) {
                throw new InvalidArgumentException("Stored rate for [{$kind}] is not an integer.");
            }

            $rates[(string) $kind] = $rate;
        }

        return new self($rates);
    }

    /** Nanodollars per 1M tokens, or null when the snapshot has no rate for this kind. */
    public function rate(UsageKind $kind): ?int
    {
        return $this->nanosPerMillion[$kind->value] ?? null;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return $this->nanosPerMillion;
    }
}
