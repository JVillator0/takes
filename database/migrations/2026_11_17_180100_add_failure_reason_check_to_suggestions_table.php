<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `suggestions.failure_reason` is an enum-like varchar, so it gets the CHECK
 * built from `App\Enums\Suggestions\SuggestionFailureReason` (spec 3-02,
 * architecture.md). The list is spelled out so history does not change when
 * the enum does; EnumCheckParityTest keeps both sides identical. NULL stays
 * allowed (every status but `failed`). Every existing value was written
 * through the enum cast, so the constraint validates on existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE suggestions ADD CONSTRAINT suggestions_failure_reason_check CHECK (failure_reason IN ('timeout', 'provider_error', 'refusal', 'invalid_output'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE suggestions DROP CONSTRAINT suggestions_failure_reason_check');
    }
};
