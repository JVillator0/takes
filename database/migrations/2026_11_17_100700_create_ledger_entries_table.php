<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only, real money only (spec 3-03 §5). D-024: holds are state on
 * usage records, never ledger rows. `customers.balance_nanos` = Σ amount_nanos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('type');
            // Signed: credits are positive, charges negative.
            $table->bigInteger('amount_nanos');
            $table->foreignUlid('usage_record_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_id')->nullable()->constrained()->restrictOnDelete();
            // The admin who made an adjustment.
            $table->foreignId('admin_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['customer_id', 'created_at']);
        });

        $checks = [
            'ledger_entries_type_check' => "type IN ('purchase', 'promo_grant', 'charge', 'adjustment')",
            // Credits add, charges subtract, an adjustment is never a no-op.
            'ledger_entries_sign_check' => "(type IN ('purchase', 'promo_grant') AND amount_nanos > 0) OR (type = 'charge' AND amount_nanos < 0) OR (type = 'adjustment' AND amount_nanos <> 0)",
            'ledger_entries_charge_has_usage_check' => "type <> 'charge' OR usage_record_id IS NOT NULL",
            'ledger_entries_purchase_has_purchase_check' => "type <> 'purchase' OR purchase_id IS NOT NULL",
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        // A usage is charged once; a purchase credits once; one welcome credit per customer (D-019).
        DB::statement("CREATE UNIQUE INDEX ledger_entries_charge_usage_record_id_unique ON ledger_entries (usage_record_id) WHERE type = 'charge'");
        DB::statement("CREATE UNIQUE INDEX ledger_entries_purchase_purchase_id_unique ON ledger_entries (purchase_id) WHERE type = 'purchase'");
        DB::statement("CREATE UNIQUE INDEX ledger_entries_welcome_grant_unique ON ledger_entries (customer_id) WHERE type = 'promo_grant' AND reason = 'welcome'");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
