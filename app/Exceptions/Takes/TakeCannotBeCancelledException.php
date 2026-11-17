<?php

declare(strict_types=1);

namespace App\Exceptions\Takes;

use App\Models\Take;
use RuntimeException;

/**
 * Cancel is only for a take waiting for capacity with no create call in
 * flight (D-044): otherwise Pixelforge may already have the generation.
 * Rendered as 422 `cannot_cancel` (spec 4 "Cancel").
 */
final class TakeCannotBeCancelledException extends RuntimeException
{
    public const string CODE = 'cannot_cancel';

    public static function for(Take $take): self
    {
        $kind = $take->last_submit_error_kind->value ?? 'none';

        return new self("Take {$take->id} cannot be cancelled: it is {$take->status->value} with submit error kind [{$kind}].");
    }
}
