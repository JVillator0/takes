<?php

declare(strict_types=1);

namespace App\Exceptions\Suggestions;

use RuntimeException;

/**
 * The model's output failed server-side re-validation (spec 8): the
 * suggestion fails and its usage is voided. The message is for logs only.
 */
final class InvalidProposalException extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("Invalid take change proposal: {$reason}.");
    }
}
