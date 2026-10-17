<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_env_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Relative to the deploy folder: .env, src/api/.env, prod.env.
            $table->string('path');
            $table->string('role', 20)->default('app');
            // Encrypted with APP_KEY. The hash (keyed with APP_KEY) tells
            // versions apart without decrypting them.
            $table->text('content');
            $table->string('content_hash', 64);
            $table->unsignedSmallInteger('key_count')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['project_id', 'path']);
        });

        Schema::create('project_env_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_env_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('content');
            $table->string('content_hash', 64);
            $table->timestamp('created_at');

            $table->unique(['project_env_file_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_env_versions');
        Schema::dropIfExists('project_env_files');
    }
};
