<?php

declare(strict_types=1);

namespace App\Exceptions\Takes;

use RuntimeException;
use Throwable;

/**
 * Applying a suggestion needs spec 4's CreateTakeAction behind the
 * CreatesSuggestedTake seam; until that binding exists, apply refuses
 * instead of creating a take outside the take pipeline.
 */
final class TakeCreationUnavailableException extends RuntimeException
{
    public static function notBound(): self
    {
        return new self('Take creation (spec 4 CreateTakeAction) is not bound to CreatesSuggestedTake yet.');
    }

    /** The image service has no API key: no take is created or held (spec 3-04 issue 4). */
    public static function serviceNotConfigured(Throwable $previous): self
    {
        return new self('Image generation is not configured on this server.', 0, $previous);
    }
}
