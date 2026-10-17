<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A project assigned to a server: it deploys to {base_dir}/{slug}.
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('base_dir');
            // Stored, so renaming the project never moves an existing deploy.
            $table->string('path');
            $table->string('health', 20)->default('unknown');
            $table->timestamp('bootstrapped_at')->nullable();
            $table->timestamp('last_deployed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX targets_project_server_active_unique ON targets (project_id, server_id) WHERE archived_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX targets_server_path_active_unique ON targets (server_id, path) WHERE archived_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('targets');
    }
};
