<?php

declare(strict_types=1);

namespace App\Exceptions\Takes;

use RuntimeException;

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
}
