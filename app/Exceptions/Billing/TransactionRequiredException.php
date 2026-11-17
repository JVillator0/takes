<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The money actions run inside the caller's transaction (spec 3-03 §6), so the
 * take/suggestion state change and the money move commit or roll back together.
 * A real exception, not assert(): it must hold with zend.assertions disabled.
 */
final class TransactionRequiredException extends LogicException
{
    /** @param  class-string  $action */
    public static function unlessInTransaction(string $action): void
    {
        if (DB::transactionLevel() === 0) {
            throw new self("{$action} must run inside the caller's database transaction.");
        }
    }
}
