<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per billable provider call, Pixelforge or Anthropic (spec 3-03 §5,
 * D-009). D-024: `max_charge_nanos` of an open usage IS the customer's hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('provider');
            // Morph map aliases (`take`, `suggestion`), never class names.
            $table->string('billable_type');
            $table->ulid('billable_id');
            $table->string('model')->nullable();
            $table->string('status')->default('open');
            // From the estimate (D-055).
            $table->bigInteger('min_cost_nanos');
            $table->bigInteger('max_cost_nanos');
            $table->bigInteger('min_charge_nanos');
            $table->bigInteger('max_charge_nanos');
            // Set at settlement.
            $table->jsonb('units')->nullable();
            // Snapshot at open, overwritten at settle with the generation's rates (D-055).
            $table->jsonb('rates');
            $table->integer('input_text_tokens')->nullable();
            $table->integer('input_image_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();
            // D-054: over_estimate, cost_mismatch, orphan.
            $table->jsonb('flags')->default('[]');
            // Null while open; 0 when voided.
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charge_nanos')->nullable();
            $table->integer('markup_bps');
            $table->string('external_id')->nullable()->index();
            $table->timestampTz('completed_at')->nullable();
            // UTC date the provider completed the work (Pixelforge's billing rule).
            $table->date('billing_date')->nullable();
            $table->timestampsTz();

            $table->unique(['billable_type', 'billable_id']);
            // Target of ledger_entries' same-customer (usage_record_id, customer_id) FK.
            $table->unique(['id', 'customer_id']);
            $table->index(['provider', 'billing_date']);
            $table->index(['customer_id', 'billing_date']);
        });

        $checks = [
            'usage_records_provider_check' => "provider IN ('pixelforge', 'anthropic')",
            'usage_records_status_check' => "status IN ('open', 'settled', 'voided')",
            'usage_records_billable_type_check' => "billable_type IN ('take', 'suggestion')",
            'usage_records_tokens_check' => 'input_text_tokens >= 0 AND input_image_tokens >= 0 AND output_tokens >= 0 AND reasoning_tokens >= 0',
            'usage_records_amounts_check' => 'min_cost_nanos >= 0 AND max_cost_nanos >= 0 AND min_charge_nanos >= 0 AND max_charge_nanos >= 0 AND provider_cost_nanos >= 0 AND charge_nanos >= 0 AND markup_bps >= 0',
            'usage_records_estimate_range_check' => 'min_cost_nanos <= max_cost_nanos AND min_charge_nanos <= max_charge_nanos',
            'usage_records_flags_check' => "jsonb_typeof(flags) = 'array'",
            'usage_records_open_check' => "status <> 'open' OR (provider_cost_nanos IS NULL AND charge_nanos IS NULL)",
            'usage_records_settled_check' => "status <> 'settled' OR (provider_cost_nanos IS NOT NULL AND charge_nanos IS NOT NULL AND billing_date IS NOT NULL)",
            'usage_records_voided_check' => "status <> 'voided' OR (provider_cost_nanos = 0 AND charge_nanos = 0)",
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE usage_records ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        // Σ open holds per customer (held_nanos, billing:verify).
        DB::statement("CREATE INDEX usage_records_open_customer_id_index ON usage_records (customer_id) WHERE status = 'open'");
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
