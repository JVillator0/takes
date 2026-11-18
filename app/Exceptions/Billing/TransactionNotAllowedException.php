<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Support\Database\TransactionBaseline;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The mirror of TransactionRequiredException: an operation that calls a
 * provider must start with no transaction open, so no lock (customer row,
 * advisory budget lock) is held across the HTTP call (money rule M-16).
 * A real exception, not assert(): it must hold with zend.assertions disabled.
 */
final class TransactionNotAllowedException extends LogicException
{
    /**
     * Transactions opened outside the application (TransactionBaseline: 0 in
     * the application, RefreshDatabase's level in a Feature test) do not count.
     *
     * @param  class-string  $operation
     */
    public static function unlessNoTransaction(string $operation): void
    {
        if (DB::transactionLevel() > app(TransactionBaseline::class)->level) {
            throw new self("{$operation} calls a provider and must run with no database transaction open.");
        }
    }
}
