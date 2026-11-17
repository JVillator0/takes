<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Asserts that Postgres itself rejects a write. The write runs in a nested
 * transaction (a savepoint), so the failed statement does not abort the
 * test's surrounding RefreshDatabase transaction.
 */
final class Constraint
{
    public const string CHECK_VIOLATION = '23514';

    public const string UNIQUE_VIOLATION = '23505';

    public const string FOREIGN_KEY_VIOLATION = '23503';

    /** A plpgsql RAISE EXCEPTION (e.g. the append-only ledger trigger). */
    public const string RAISE_EXCEPTION = 'P0001';

    public static function assertRejects(Closure $write, string $sqlState, ?string $constraint = null, ?string $message = null): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $exception) {
            Assert::assertSame($sqlState, (string) $exception->getCode(), $exception->getMessage());

            if ($constraint !== null) {
                Assert::assertStringContainsString("\"{$constraint}\"", $exception->getMessage());
            }

            if ($message !== null) {
                Assert::assertStringContainsString($message, $exception->getMessage());
            }

            return;
        }

        Assert::fail('Expected the database to reject the write'.($constraint !== null ? " with [{$constraint}]." : '.'));
    }
}
