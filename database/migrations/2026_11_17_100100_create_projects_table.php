<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->rawIndex('customer_id, updated_at DESC', 'projects_customer_id_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
