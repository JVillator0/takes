<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Providers › Webhook deliveries counts the last 24 hours of `webhook_events` by `received_at` (spec 7). The table
 * is append-only, so the count needs an index, as `webhook_deliveries.received_at` already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->dropIndex(['received_at']);
        });
    }
};
