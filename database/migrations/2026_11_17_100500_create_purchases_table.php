<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Simulated credit purchases (spec 3-03 §5, D-051). ulid id (D-067) so
 * `ledger_entries.purchase_id` and spec 6's `invoices.purchase_id` are ulid FKs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('reference')->unique();
            $table->bigInteger('amount_nanos');
            $table->string('status')->default('pending');
            $table->timestampTz('paid_at')->nullable();
            $table->uuid('idempotency_key');
            $table->timestampsTz();

            // A replayed purchase request creates nothing.
            $table->unique(['customer_id', 'idempotency_key']);
        });

        $checks = [
            'purchases_status_check' => "status IN ('pending', 'paid', 'failed')",
            'purchases_amount_nanos_check' => 'amount_nanos > 0',
            'purchases_paid_has_paid_at_check' => "status <> 'paid' OR paid_at IS NOT NULL",
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE purchases ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
