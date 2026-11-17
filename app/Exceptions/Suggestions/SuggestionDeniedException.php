<?php

declare(strict_types=1);

namespace App\Exceptions\Suggestions;

use App\Enums\Auth\DenialReason;
use RuntimeException;

/**
 * SuggestionPolicy::create denied the request when re-checked under the
 * customer lock (D-015), or the hold did not fit (`insufficient_credits`).
 */
final class SuggestionDeniedException extends RuntimeException
{
    public function __construct(public readonly DenialReason $reason)
    {
        parent::__construct("Suggestion denied: {$reason->value}.");
    }
}
