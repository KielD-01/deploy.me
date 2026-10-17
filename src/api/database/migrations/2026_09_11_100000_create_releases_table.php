<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What one deploy put on a server: a folder under releases/ for
        // versionable projects, or a record of an in-place deploy.
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            // UTC YmdHis: the folder name under releases/.
            $table->string('name', 24);
            $table->string('path');
            $table->string('layout', 10);
            $table->string('commit_sha', 40)->nullable();
            $table->string('commit_message')->nullable();
            $table->string('strategy', 20)->nullable();
            $table->string('status', 20)->default('building');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('ran_migrations')->default(false);
            $table->boolean('adopted')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();

            $table->unique(['target_id', 'name']);
            $table->index(['target_id', 'status']);
        });

        Schema::table('targets', function (Blueprint $table) {
            $table->foreignId('current_release_id')->nullable()->constrained('releases')->nullOnDelete();
            $table->foreignId('previous_release_id')->nullable()->constrained('releases')->nullOnDelete();
        });

        Schema::table('deployments', function (Blueprint $table) {
            // The release this run made (a deploy) or switched to (a rollback),
            // and the one that was live before it.
            $table->foreignId('release_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('previous_release_id')->nullable()->constrained('releases')->nullOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            // Folders every release shares (storage/), relative to the project.
            $table->json('shared_paths')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('shared_paths'));
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_release_id');
            $table->dropConstrainedForeignId('release_id');
        });
        Schema::table('targets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_release_id');
            $table->dropConstrainedForeignId('current_release_id');
        });
        Schema::dropIfExists('releases');
    }
};
