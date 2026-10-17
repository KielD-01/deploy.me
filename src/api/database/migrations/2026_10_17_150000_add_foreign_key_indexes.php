<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL indexes a primary key and a unique constraint, not a foreign key.
 * These are the ones a query actually filters or groups by:
 *
 * - deployments (target_id, id), (project_id, id), (server_id, id): "the
 *   newest run of each target" (`latestOfMany` / `ofMany` are MAX(id) GROUP BY
 *   target_id), a project's and a server's runs newest first (the run list,
 *   Console/Git, the MCP tools, PollCi's "already deployed this commit?").
 * - deployments (finished_at) over finished runs: PruneOldData's daily sweep.
 * - sync_runs (folder_mapping_id, id): a mapping's newest run, newest real
 *   run and the prune's max(id) per mapping.
 * - folder_mappings (server_id): a server's mappings.
 * - projects (credential_id): the token's project count on Settings.
 *
 * Left out on purpose: targets (project_id) and (server_id), which the two
 * partial unique indexes (project_id, server_id) and (server_id, path) already
 * serve — every query on targets also says `archived_at is null` — and
 * releases (deployment_id), which only ON DELETE SET NULL reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->index(['target_id', 'id'], 'deployments_target_id_id_index');
            $table->index(['project_id', 'id'], 'deployments_project_id_id_index');
            $table->index(['server_id', 'id'], 'deployments_server_id_id_index');
        });

        // The statuses are DeploymentStatus::ACTIVE; the same words as the
        // query, so the planner can see that one implies the other.
        DB::statement("create index deployments_finished_at_index on deployments (finished_at) where status not in ('queued', 'waiting_ci', 'running')");

        Schema::table('sync_runs', fn (Blueprint $table) => $table->index(['folder_mapping_id', 'id'], 'sync_runs_folder_mapping_id_id_index'));
        Schema::table('folder_mappings', fn (Blueprint $table) => $table->index('server_id', 'folder_mappings_server_id_index'));
        Schema::table('projects', fn (Blueprint $table) => $table->index('credential_id', 'projects_credential_id_index'));
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropIndex('projects_credential_id_index'));
        Schema::table('folder_mappings', fn (Blueprint $table) => $table->dropIndex('folder_mappings_server_id_index'));
        Schema::table('sync_runs', fn (Blueprint $table) => $table->dropIndex('sync_runs_folder_mapping_id_id_index'));
        DB::statement('drop index if exists deployments_finished_at_index');

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropIndex('deployments_server_id_id_index');
            $table->dropIndex('deployments_project_id_id_index');
            $table->dropIndex('deployments_target_id_id_index');
        });
    }
};
