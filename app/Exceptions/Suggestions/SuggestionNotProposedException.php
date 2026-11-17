<?php

declare(strict_types=1);

namespace App\Exceptions\Suggestions;

use App\Enums\Suggestions\SuggestionStatus;
use RuntimeException;

/**
 * Apply and discard need a `proposed` suggestion (spec 8 step 4).
 */
final class SuggestionNotProposedException extends RuntimeException
{
    public static function for(string $suggestionId, SuggestionStatus $status): self
    {
        return new self("Suggestion {$suggestionId} is {$status->value}, not proposed.");
    }
}
