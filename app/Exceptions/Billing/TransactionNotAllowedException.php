<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

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
     * Transactions opened by an outer test harness, not by the application:
     * RefreshDatabase wraps every Feature test in one (tests/Pest.php sets
     * this per test). Always 0 in the application.
     */
    public static int $harnessLevel = 0;

    /** @param  class-string  $operation */
    public static function unlessNoTransaction(string $operation): void
    {
        if (DB::transactionLevel() > self::$harnessLevel) {
            throw new self("{$operation} calls a provider and must run with no database transaction open.");
        }
    }
}
