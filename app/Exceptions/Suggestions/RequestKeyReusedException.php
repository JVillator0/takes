<?php

declare(strict_types=1);

namespace App\Exceptions\Suggestions;

use RuntimeException;

/**
 * A request key already used for a suggestion on another base take (D-033
 * pattern: a replay must carry the same payload, otherwise 409).
 */
final class RequestKeyReusedException extends RuntimeException
{
    public static function for(string $requestKey): self
    {
        return new self("Request key {$requestKey} was already used for a suggestion on another take.");
    }
}
