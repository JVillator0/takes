<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * A purchase idempotency key was replayed with a different pack. A replay
 * must be the same request; anything else is refused (HTTP 409).
 */
final class IdempotencyKeyReusedException extends RuntimeException
{
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct("Idempotency key [{$idempotencyKey}] was already used for a different purchase.");
    }
}
