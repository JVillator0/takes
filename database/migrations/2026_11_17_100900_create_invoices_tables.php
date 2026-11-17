<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One invoice per purchase (spec 6, D-022, D-029, D-051): no invoice lines
 * (one purchase is one line), no tax, no statements. Numbers are
 * `INV-{YYYY}-{NNNNNN}`, gapless per year through a locked counter row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_counters', function (Blueprint $table): void {
            $table->smallInteger('year')->primary();
            // The last number issued in that year; the next invoice takes +1.
            $table->integer('last_number')->default(0);
        });

        DB::statement('ALTER TABLE invoice_counters ADD CONSTRAINT invoice_counters_last_number_check CHECK (last_number >= 0)');

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('number')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // One invoice per purchase, and only for that purchase's own customer.
            $table->ulid('purchase_id')->unique();
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('total_nanos');
            $table->string('status')->default('paid');
            $table->timestampTz('issued_at');
            // Billing snapshot: a later profile change never rewrites an issued invoice.
            $table->string('billed_name');
            $table->string('billed_email');
            $table->timestampsTz();

            $table->index(['customer_id', 'issued_at']);
            $table->foreign(['purchase_id', 'customer_id'])->references(['id', 'customer_id'])->on('purchases')->restrictOnDelete();
        });

        $checks = [
            'invoices_status_check' => "status IN ('paid')",
            'invoices_currency_check' => "currency = 'USD'",
            'invoices_total_nanos_check' => 'total_nanos > 0',
            'invoices_number_format_check' => "number ~ '^INV-[0-9]{4}-[0-9]{6}$'",
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_counters');
    }
};
