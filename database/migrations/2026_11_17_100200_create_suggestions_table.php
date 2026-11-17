<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Describe a change" suggestions: the single column list (spec 3-02; spec 8
 * adds behavior only, D-052). Created before `takes` so `takes.suggestion_id`
 * can reference it; the FKs from here to `takes` (base_take_id,
 * resulting_take_id) are added by the takes migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggestions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Same-customer FK below.
            $table->ulid('project_id');
            // The take the instruction applies to (FK added with `takes`).
            $table->ulid('base_take_id')->index();
            $table->text('instruction');
            // Client-minted; a replay returns the existing suggestion.
            $table->uuid('request_key');
            $table->jsonb('proposal')->nullable();
            $table->text('rationale')->nullable();
            $table->string('model')->nullable();
            $table->string('status')->default('pending');
            // e.g. `timeout`: a reason, not a status.
            $table->string('failure_reason')->nullable();
            // The take created from the proposal (FK added with `takes`).
            $table->ulid('resulting_take_id')->nullable()->index();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->foreign(['project_id', 'customer_id'])->references(['id', 'customer_id'])->on('projects')->restrictOnDelete();
            $table->unique(['customer_id', 'request_key']);
            // Target of takes' same-customer (suggestion_id, customer_id) FK.
            $table->unique(['id', 'customer_id']);
            $table->index(['customer_id', 'created_at']);
        });

        DB::statement("ALTER TABLE suggestions ADD CONSTRAINT suggestions_status_check CHECK (status IN ('pending', 'proposed', 'applied', 'discarded', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestions');
    }
};
