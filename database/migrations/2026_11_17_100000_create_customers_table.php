<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers: their own table and guard, separate from admin `users` (D-012).
 * Customers are never hard-deleted; money tables reference them with restrict FKs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            // Unused: registration has no email verification (D-030).
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            // Ephemeral "Try a demo account" customers (D-045, D-065).
            $table->boolean('is_demo')->default(false);
            $table->timestampTz('demo_expires_at')->nullable();
            // D-024: cached Σ ledger (real money only) and Σ open usage holds.
            $table->bigInteger('balance_nanos')->default(0);
            $table->bigInteger('held_nanos')->default(0);
            $table->timestampTz('last_active_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_held_nanos_check CHECK (held_nanos >= 0)');

        // Separate token table so customer and admin resets never touch each other (spec 3-01).
        Schema::create('customer_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_reset_tokens');
        Schema::dropIfExists('customers');
    }
};
