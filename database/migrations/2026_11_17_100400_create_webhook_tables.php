<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-027: `webhook_events` records each distinct event once (no outcome);
 * `webhook_deliveries` is append-only, one row per verified delivery, and is
 * the only table that holds the outcome. A bad signature writes nothing (D-056).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('generation_id')->nullable()->index();
            $table->jsonb('payload');
            $table->timestampTz('received_at');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->index();
            $table->foreignId('webhook_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('take_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('type');
            $table->integer('attempt');
            $table->string('outcome');
            $table->timestampTz('received_at')->index();
        });

        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_outcome_check CHECK (outcome IN ('applied', 'ignored_same_state', 'ignored_stale', 'ignored_conflict', 'orphan_success', 'invalid_snapshot', 'duplicate', 'ping', 'unknown_take', 'ignored_type'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_events');
    }
};
