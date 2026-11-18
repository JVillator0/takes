<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The provider cost of an orphan success (D-025, D-077): Pixelforge ran and
 * bills a generation we had already failed free. The usage stays voided with
 * cost and charge 0 and nothing reaches the ledger; this column records what
 * the platform absorbed (units × the generation's rates), on the generation's
 * billing day, so `billing:verify --usage` can attribute it (issue #102, D-058).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->bigInteger('absorbed_cost_nanos')->nullable()->after('charge_nanos');
        });

        // Only a voided usage absorbs a cost, never negative, always on a billing day.
        DB::statement("ALTER TABLE usage_records ADD CONSTRAINT usage_records_absorbed_check CHECK (absorbed_cost_nanos IS NULL OR (status = 'voided' AND absorbed_cost_nanos >= 0 AND billing_date IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE usage_records DROP CONSTRAINT usage_records_absorbed_check');

        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropColumn('absorbed_cost_nanos');
        });
    }
};
