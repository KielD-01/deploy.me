<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // The folder name on servers: {base_dir}/{slug}.
            $table->string('slug')->unique();
            $table->string('source', 20);

            // A local folder (mounted at the same path inside the containers),
            // and/or the GitHub repository behind it.
            $table->string('local_path')->nullable();
            $table->string('git_remote')->nullable();
            $table->string('github_owner')->nullable();
            $table->string('github_repo')->nullable();
            $table->unsignedBigInteger('github_repo_id')->nullable();
            $table->foreignId('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('branch')->default('main');
            $table->string('framework')->nullable();

            // How every target of this project is deployed.
            $table->boolean('versionable')->default(true);
            $table->unsignedTinyInteger('keep_releases')->default(5);
            $table->boolean('dockerized')->default(false);
            $table->string('stack_name')->nullable();
            $table->json('compose_files')->nullable();
            $table->json('app_services')->nullable();
            $table->string('migrate_service')->nullable();

            // Ordered: the first applicable strategy wins.
            $table->json('delivery_strategies');
            $table->boolean('wait_for_ci')->default(true);

            $table->string('health_url')->nullable();
            $table->unsignedSmallInteger('health_timeout')->default(180);
            $table->boolean('auto_rollback')->default(true);
            $table->string('activation', 20)->default('builtin');
            $table->string('activation_script')->nullable();

            $table->json('detection')->nullable();
            $table->timestamp('detected_at')->nullable();

            // Latest GitHub Actions state for the deploy branch.
            $table->string('ci_state', 20)->nullable();
            $table->json('ci_run')->nullable();
            $table->string('ci_etag')->nullable();
            $table->timestamp('ci_checked_at')->nullable()->index();

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_keep_releases_range CHECK (keep_releases BETWEEN 1 AND 10)');

        // One live project per folder and per GitHub repository.
        DB::statement('CREATE UNIQUE INDEX projects_local_path_active_unique ON projects (local_path) WHERE archived_at IS NULL AND local_path IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX projects_github_repo_active_unique ON projects (lower(github_owner), lower(github_repo)) WHERE archived_at IS NULL AND source = 'github'");
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
