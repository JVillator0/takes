<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's money queries (D-086: GetMoneyTotalsQuery, ListDailySpendCostQuery, ListProviderCostSplitQuery)
 * sum settled usage over a range of billing days across every provider and customer; the existing indexes lead
 * on provider or customer_id. A partial index on billing_date for settled rows serves those ranges.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX usage_records_settled_billing_date_index ON usage_records (billing_date) WHERE status = 'settled'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS usage_records_settled_billing_date_index');
    }
};
