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
    /**
     * @param  array<string, int>  $tokens  token count keyed by UsageKind value
     */
    public function __construct(public array $tokens)
    {
        foreach ($tokens as $kind => $count) {
            if (UsageKind::tryFrom((string) $kind) === null) {
                throw new InvalidArgumentException("Unknown usage kind [{$kind}].");
            }

            if ($count < 0) {
                throw new InvalidArgumentException("Token count for [{$kind}] cannot be negative.");
            }
        }
    }

    /** Null when the provider reported no count for this kind. */
    public function tokens(UsageKind $kind): ?int
    {
        return $this->tokens[$kind->value] ?? null;
    }
}
