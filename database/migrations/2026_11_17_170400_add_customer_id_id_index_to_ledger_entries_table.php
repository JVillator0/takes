<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing › Reports' promo/adjustment credits consumed (D-084) walks each customer's ledger in id order
 * (`ORDER BY customer_id, id`); the table only had (customer_id, created_at), so the walk needs this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->index(['customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'id']);
        });
    }
};
