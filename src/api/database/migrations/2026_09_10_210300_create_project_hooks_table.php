<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 20);
            $table->string('name');
            $table->text('command');
            // On the server in the release folder, or inside a Compose service.
            $table->string('run_in', 20)->default('host');
            $table->string('service')->nullable();
            $table->unsignedSmallInteger('timeout')->default(300);
            $table->boolean('continue_on_error')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'phase', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_hooks');
    }
};
