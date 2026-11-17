<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-activitylog's table: the console's audit of what admins write (spec 7, A07).
 *
 * The subject id is a string, not the package's bigint: the audited models mix bigint keys (customers, users)
 * with ULIDs (takes, projects, suggestions, usage records, purchases). The causer is always an admin (`users`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 36)->nullable();
            $table->index(['subject_type', 'subject_id'], 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
