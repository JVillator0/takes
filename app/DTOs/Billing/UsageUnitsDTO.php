<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\Billing\UsageKind;
use InvalidArgumentException;

/**
 * Units consumed by one provider call: token counts by kind (spec 3-03 §2).
 * Provider-agnostic; each provider's client maps its own usage object into it.
 */
final readonly class UsageUnitsDTO
{
    /** @var array<string, int> token count keyed by UsageKind value */
    public array $tokens;

    /**
     * Validates at runtime: the counts come from provider payloads, where a
     * float or numeric string must never reach the cost math.
     *
     * @param  array<array-key, mixed>  $tokens  token count keyed by UsageKind value
     */
    public function __construct(array $tokens)
    {
        $validated = [];

        foreach ($tokens as $kind => $count) {
            if (UsageKind::tryFrom((string) $kind) === null) {
                throw new InvalidArgumentException("Unknown usage kind [{$kind}].");
            }

            if (! is_int($count)) {
                throw new InvalidArgumentException("Token count for [{$kind}] must be an integer.");
            }

            if ($count < 0) {
                throw new InvalidArgumentException("Token count for [{$kind}] cannot be negative.");
            }

            $validated[(string) $kind] = $count;
        }

        $this->tokens = $validated;
    }

    /** Null when the provider reported no count for this kind. */
    public function tokens(UsageKind $kind): ?int
    {
        return $this->tokens[$kind->value] ?? null;
    }
}
