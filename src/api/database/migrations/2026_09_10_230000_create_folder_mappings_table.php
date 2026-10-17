<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A folder on the Mac paired with a folder on a server, synced one way
        // with rsync: push (Mac → server) or pull (server → Mac).
        Schema::create('folder_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('direction', 10);
            $table->string('local_path');
            $table->string('remote_path');
            $table->json('excludes')->nullable();
            $table->boolean('delete_extraneous')->default(false);
            // Null: only by hand.
            $table->unsignedInteger('schedule_interval')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamps();
        });

        // One rsync run: a dry run (the preview) or the real thing.
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_mapping_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('dry_run')->default(false);
            $table->string('status', 20)->default('queued');
            $table->string('trigger', 20)->default('manual');
            // The most files this run may delete (rsync --max-delete).
            $table->unsignedInteger('max_delete')->nullable();
            $table->json('stats')->nullable();
            // The first changes rsync listed, for the preview.
            $table->json('changes')->nullable();
            $table->unsignedInteger('change_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamps();
        });

        DB::statement("CREATE UNIQUE INDEX sync_runs_one_active_per_mapping ON sync_runs (folder_mapping_id) WHERE status IN ('queued', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('folder_mappings');
    }
};
