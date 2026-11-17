<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Takes: one generation request and its result (spec 3-02). Money for a take
 * lives in its usage record (spec 3-03), never here.
 *
 * Takes (and suggestions) are never hard-deleted: usage_records reference them
 * through a morph pair (billable_type, billable_id) that cannot carry an FK,
 * so a delete would orphan money rows. No code path deletes them.
 *
 * Enum-like columns are varchar + CHECK, never native Postgres enums; the PHP
 * backed enums in App\Enums\Takes are the source of each list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('takes', function (Blueprint $table): void {
            // Also sent to Pixelforge as metadata.app_take_id.
            $table->ulid('id')->primary();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Same-customer FK below.
            $table->ulid('project_id');
            // Remix / AI lineage (same-customer self-referencing FK added below, after the keys exist).
            $table->ulid('parent_take_id')->nullable()->index();
            // Same-customer FK below.
            $table->ulid('suggestion_id')->nullable();
            $table->string('source');
            // D-033: minted by the composer, reused as Pixelforge's Idempotency-Key.
            $table->uuid('idempotency_key');
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status')->default('pending');
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images')->default('[]');
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            // D-025: the 30-min retry window is measured from created_at.
            $table->smallInteger('submit_attempts')->default(0);
            $table->text('last_submit_error')->nullable();
            $table->string('last_submit_error_kind')->nullable();
            // D-053: last provider update, basis of the "stuck" rule.
            $table->timestampTz('last_event_at')->nullable();
            // D-054: durable Needs attention flag.
            $table->string('attention_reason')->nullable();
            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            // D-020: persisted copy on our disk.
            $table->string('image_path')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['project_id', 'customer_id'])->references(['id', 'customer_id'])->on('projects')->restrictOnDelete();
            $table->foreign(['suggestion_id', 'customer_id'])->references(['id', 'customer_id'])->on('suggestions')->restrictOnDelete();
            $table->unique(['customer_id', 'idempotency_key']);
            // Target of the same-customer (…_take_id, customer_id) FKs.
            $table->unique(['id', 'customer_id']);
            $table->rawIndex('customer_id, created_at DESC', 'takes_customer_id_created_at_index');
            $table->rawIndex('project_id, created_at DESC', 'takes_project_id_created_at_index');
            // The sweeper (D-036, D-053).
            $table->index(['status', 'created_at']);
        });

        $checks = [
            'takes_source_check' => "source IN ('composer', 'remix', 'ai_change')",
            'takes_status_check' => "status IN ('pending', 'queued', 'processing', 'succeeded', 'failed')",
            'takes_size_check' => "size IN ('1024x1024', '1536x1024', '1024x1536')",
            'takes_quality_check' => "quality IN ('low', 'medium', 'high')",
            'takes_failure_code_check' => "failure_code IN ('generation_failed', 'internal_error', 'content_policy', 'daily_limit', 'validation', 'internal', 'provider_busy', 'submit_timeout', 'cancelled')",
            'takes_last_submit_error_kind_check' => "last_submit_error_kind IN ('capacity', 'ambiguous')",
            'takes_attention_reason_check' => "attention_reason IN ('invalid_snapshot', 'orphan_success', 'cost_mismatch', 'reconcile_exhausted', 'internal_failure', 'over_estimate')",
            // Same count as mb_strlen: Unicode characters, not bytes (spec 3-03 §4).
            'takes_prompt_length_check' => 'char_length(prompt) <= 4000',
            'takes_reference_images_check' => "jsonb_typeof(reference_images) = 'array' AND jsonb_array_length(reference_images) <= 4",
            'takes_submit_attempts_check' => 'submit_attempts >= 0',
            'takes_failed_has_code_check' => "status <> 'failed' OR failure_code IS NOT NULL",
            'takes_succeeded_has_result_check' => "status <> 'succeeded' OR (completed_at IS NOT NULL AND image_url IS NOT NULL)",
            'takes_terminal_has_completed_at_check' => "status NOT IN ('succeeded', 'failed') OR completed_at IS NOT NULL",
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE takes ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        // In-flight takes: the concurrency gate and the sweeper only scan these.
        DB::statement("CREATE INDEX takes_in_flight_index ON takes (created_at) WHERE status IN ('pending', 'queued', 'processing')");

        // One take per AI proposal.
        DB::statement('CREATE UNIQUE INDEX takes_suggestion_id_unique ON takes (suggestion_id) WHERE suggestion_id IS NOT NULL');

        // Every take ↔ take and take ↔ suggestion link stays within one customer
        // (composite FKs; a NULL id skips the check, MATCH SIMPLE).
        Schema::table('takes', function (Blueprint $table): void {
            $table->foreign(['parent_take_id', 'customer_id'])->references(['id', 'customer_id'])->on('takes')->restrictOnDelete();
        });

        // Close the suggestions ↔ takes cycle now that `takes` exists.
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->foreign(['base_take_id', 'customer_id'])->references(['id', 'customer_id'])->on('takes')->restrictOnDelete();
            $table->foreign(['resulting_take_id', 'customer_id'])->references(['id', 'customer_id'])->on('takes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->dropForeign(['base_take_id', 'customer_id']);
            $table->dropForeign(['resulting_take_id', 'customer_id']);
        });

        Schema::dropIfExists('takes');
    }
};
