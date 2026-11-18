<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider cost we paid but never charged (spec 3-03 §5, spec 8 Guardrails):
 * an AI call that consumed tokens and then failed (refusal, `max_tokens`,
 * output that fails re-validation, or a result that came back after the
 * suggestion expired) is voided, so the customer pays nothing (M-13), but
 * Anthropic bills those tokens. The global daily AI budget (`ai_paused`)
 * counts them by `completed_at` (UTC day), so the CHECK requires it. D-087.
 * Admin-only (D-023); `provider_cost_nanos` and `charge_nanos` of a voided
 * usage stay 0, so no report or ledger sum changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            // Null unless a voided usage absorbed provider cost.
            $table->bigInteger('absorbed_cost_nanos')->nullable()->after('charge_nanos');
        });

        DB::statement("ALTER TABLE usage_records ADD CONSTRAINT usage_records_absorbed_cost_check CHECK (absorbed_cost_nanos IS NULL OR (status = 'voided' AND absorbed_cost_nanos >= 0 AND completed_at IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE usage_records DROP CONSTRAINT usage_records_absorbed_cost_check');

        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropColumn('absorbed_cost_nanos');
        });
    }
};
