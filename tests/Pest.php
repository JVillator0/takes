<?php

declare(strict_types=1);

use App\Support\Database\TransactionBaseline;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the application and run inside a refreshed database.
| Arch tests boot the application (for base_path() and class discovery)
| but never touch the database. Unit tests stay framework-free.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // RefreshDatabase's own transaction is not the application's (money rule M-16 guard).
    ->beforeEach(fn () => app()->instance(TransactionBaseline::class, new TransactionBaseline(DB::transactionLevel())))
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Arch');

pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->in('Committed');
