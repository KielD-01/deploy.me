<?php

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Support\Facades\DB;

// These read PostgreSQL's catalogue and planner (pg_indexes, explain), which
// the slim flavour's SQLite doesn't have: there they're skipped, and the last
// tests below check SQLite's own copies of the partial indexes.
function onlyOnPostgres(): void
{
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('PostgreSQL only: it reads pg_indexes and explain.');
    }
}

// Postgres doesn't index foreign keys; these are the ones the queries filter
// by (see the migration for which, and which were left out on purpose).
it('indexes what the run lists, the latest-run lookups and the prune filter by', function (string $name, string $columns) {
    onlyOnPostgres();
    $definition = DB::selectOne('select indexdef from pg_indexes where indexname = ?', [$name])?->indexdef;

    expect($definition)->not->toBeNull()
        ->and($definition)->toContain("({$columns})");
})->with([
    ['deployments_target_id_id_index', 'target_id, id'],
    ['deployments_project_id_id_index', 'project_id, id'],
    ['deployments_server_id_id_index', 'server_id, id'],
    ['deployments_finished_at_index', 'finished_at'],
    ['sync_runs_folder_mapping_id_id_index', 'folder_mapping_id, id'],
    ['folder_mappings_server_id_index', 'server_id'],
    ['projects_credential_id_index', 'credential_id'],
]);

it('keeps the finished-runs index to the statuses a run is not active in', function () {
    onlyOnPostgres();
    $definition = DB::selectOne("select indexdef from pg_indexes where indexname = 'deployments_finished_at_index'")->indexdef;

    foreach (DeploymentStatus::ACTIVE as $status) {
        expect($definition)->toContain("'{$status}'");
    }
});

it('lets the prune query use the finished-runs index', function () {
    onlyOnPostgres();
    DB::statement('set local enable_seqscan = off');

    $sql = Deployment::query()
        ->whereNotIn('status', DeploymentStatus::ACTIVE)
        ->where('finished_at', '<', now()->subDays(90))
        ->toRawSql();

    $plan = collect(DB::select("explain {$sql}"))->pluck('QUERY PLAN')->implode("\n");

    expect($plan)->toContain('deployments_finished_at_index');
});

// SQLite rebuilds a table to add a foreign key, and Laravel re-creates its
// indexes without their WHERE: 2026_10_18_100000 puts them back.
it('keeps the partial unique indexes partial on SQLite', function (string $name, string $where) {
    if (DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('SQLite only: PostgreSQL keeps them through every migration.');
    }

    $sql = DB::selectOne("select sql from sqlite_master where type = 'index' and name = ?", [$name])?->sql;

    expect($sql)->not->toBeNull()->and($sql)->toContain($where);
})->with([
    ['targets_project_server_active_unique', 'WHERE archived_at IS NULL'],
    ['targets_server_path_active_unique', 'WHERE archived_at IS NULL'],
    ['deployments_one_active_per_target', "WHERE status IN ('queued', 'waiting_ci', 'running')"],
    ['deployments_finished_at_index', "where status not in ('queued', 'waiting_ci', 'running')"],
    ['servers_ssh_alias_active_unique', 'WHERE archived_at IS NULL'],
    ['projects_github_repo_active_unique', "WHERE archived_at IS NULL AND source = 'github'"],
    ['sync_runs_one_active_per_mapping', "WHERE status IN ('queued', 'running')"],
    ['audit_log_by_hand_index', "where action <> 'ssh.command'"],
]);

it('lets a target have a finished run and a new one on SQLite too', function () {
    $deployment = Deployment::factory()->create(['status' => DeploymentStatus::Succeeded]);

    Deployment::factory()->create(['target_id' => $deployment->target_id, 'project_id' => $deployment->project_id, 'server_id' => $deployment->server_id, 'status' => DeploymentStatus::Queued]);

    expect(Deployment::query()->where('target_id', $deployment->target_id)->count())->toBe(2);
});
