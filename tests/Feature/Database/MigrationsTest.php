<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Every migration is reversible (spec 3-02). Postgres DDL is transactional,
 * so the round trip runs inside the test's RefreshDatabase transaction and
 * leaves the schema as it found it.
 */
it('rolls every migration back and runs it again', function (): void {
    $tables = [
        'customers',
        'customer_password_reset_tokens',
        'projects',
        'suggestions',
        'takes',
        'webhook_events',
        'webhook_deliveries',
        'purchases',
        'usage_records',
        'ledger_entries',
    ];

    $this->artisan('migrate:reset')->assertSuccessful();

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("[{$table}] survived the rollback.");
    }

    $this->artisan('migrate')->assertSuccessful();

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("[{$table}] was not recreated.");
    }
});
