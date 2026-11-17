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
    /** @var array<string, int> nanodollars per 1M tokens, keyed by UsageKind value */
    public array $nanosPerMillion;

    /**
     * Validates at runtime: rates come from provider payloads and stored
     * jsonb, where a float or decimal string ('40.00') must never reach the
     * cost math.
     *
     * @param  array<array-key, mixed>  $nanosPerMillion  keyed by UsageKind value
     */
    public function __construct(array $nanosPerMillion)
    {
        $validated = [];

        foreach ($nanosPerMillion as $kind => $rate) {
            if (UsageKind::tryFrom((string) $kind) === null) {
                throw new InvalidArgumentException("Unknown usage kind [{$kind}].");
            }

            if (! is_int($rate)) {
                throw new InvalidArgumentException("Rate for [{$kind}] must be integer nanodollars per 1M tokens.");
            }

            if ($rate < 0) {
                throw new InvalidArgumentException("Rate for [{$kind}] cannot be negative.");
            }

            $validated[(string) $kind] = $rate;
        }

        $this->nanosPerMillion = $validated;
    }

    /**
     * Rebuilds a snapshot read back from `usage_records.rates`; the
     * constructor rejects anything that is not integer nanodollars.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        return new self($stored);
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
