<?php

declare(strict_types=1);

namespace App\Exceptions\Suggestions;

use RuntimeException;

/**
 * The base take already has a `pending` suggestion (spec 8 T1): a second one
 * is rejected with the 2-01 "AI suggestion already pending" copy.
 */
final class SuggestionAlreadyPendingException extends RuntimeException
{
    public static function forTake(string $takeId): self
    {
        return new self("Take {$takeId} already has a pending suggestion.");
    }
}
