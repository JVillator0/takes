<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use LogicException;

/**
 * The ledger is append-only (spec 3-03 §5): a correction is a new
 * `adjustment` entry, never an edit or a delete.
 */
final class ImmutableLedgerEntryException extends LogicException
{
    public static function forUpdate(): self
    {
        return new self('Ledger entries are append-only and cannot be updated; record an adjustment instead.');
    }

    public static function forDelete(): self
    {
        return new self('Ledger entries are append-only and cannot be deleted; record an adjustment instead.');
    }
}
