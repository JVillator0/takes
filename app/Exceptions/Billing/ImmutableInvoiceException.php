<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use LogicException;

/**
 * An issued invoice is final (spec 6, D-022): its number is part of the
 * gapless `INV-{YYYY}-{NNNNNN}` sequence and its amounts and billed details
 * are a snapshot of the purchase. It is never edited or deleted.
 */
final class ImmutableInvoiceException extends LogicException
{
    public static function forUpdate(): self
    {
        return new self('Issued invoices are immutable and cannot be updated.');
    }

    public static function forDelete(): self
    {
        return new self('Issued invoices are immutable and cannot be deleted; deleting one would leave a gap in the numbering.');
    }
}
