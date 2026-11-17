<?php

declare(strict_types=1);

namespace App\Enums\Suggestions;

/**
 * Why a suggestion is `failed` (`suggestions.failure_reason`, spec 8). Every
 * reason is free: the usage is voided (money rule M-13). Admin-facing; the
 * customer sees the 2-01 copy keyed by `customerCopyKey()`.
 */
enum SuggestionFailureReason: string
{
    /** Pending too long: voided by the spec 5 sweeper (ExpireSuggestionAction). */
    case Timeout = 'timeout';

    /** The call failed: connection error, request timeout, 4xx/5xx. */
    case ProviderError = 'provider_error';

    /** The model declined (`stop_reason = refusal`). */
    case Refusal = 'refusal';

    /** The output was missing, truncated or failed re-validation. */
    case InvalidOutput = 'invalid_output';

    /** The 2-01 sentence: the provider being down reads "unavailable"; the rest "failed". */
    public function customerCopyKey(): string
    {
        return $this === self::ProviderError
            ? 'suggestions.unavailable'
            : 'suggestions.failed';
    }
}
