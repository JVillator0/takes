<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * How many transactions were already open before the application code ran.
 * Always 0 in the application (the container builds it with the default).
 * A test harness that wraps each test in a transaction (RefreshDatabase)
 * binds an instance with that level, per test, in the container, so the
 * M-16 guard still means "no transaction opened by the application"
 * without a mutable static in production code.
 */
final readonly class TransactionBaseline
{
    public function __construct(public int $level = 0) {}
}
