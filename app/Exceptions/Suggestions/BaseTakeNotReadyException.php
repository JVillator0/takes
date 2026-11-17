<?php

declare(strict_types=1);

namespace App\Exceptions\Suggestions;

use RuntimeException;

/**
 * Describe a change is offered on finished takes only (2-01 state table:
 * the action belongs to `succeeded`).
 */
final class BaseTakeNotReadyException extends RuntimeException
{
    public static function for(string $takeId): self
    {
        return new self("Take {$takeId} has not succeeded; describe a change needs a finished take.");
    }
}
