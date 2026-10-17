<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per health check of a target, so a flapping app shows as a
        // history instead of only its last result. Kept for health_retention_days.
        Schema::create('target_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained()->cascadeOnDelete();
            $table->string('health', 20);
            $table->string('detail', 250)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('checked_at');

            $table->index(['target_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_health_checks');
    }
};
