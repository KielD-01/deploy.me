<?php

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Support\Facades\DB;

// Postgres doesn't index foreign keys; these are the ones the queries filter
// by (see the migration for which, and which were left out on purpose).
it('indexes what the run lists, the latest-run lookups and the prune filter by', function (string $name, string $columns) {
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
    $definition = DB::selectOne("select indexdef from pg_indexes where indexname = 'deployments_finished_at_index'")->indexdef;

    foreach (DeploymentStatus::ACTIVE as $status) {
        expect($definition)->toContain("'{$status}'");
    }
});

it('lets the prune query use the finished-runs index', function () {
    DB::statement('set local enable_seqscan = off');

    $sql = Deployment::query()
        ->whereNotIn('status', DeploymentStatus::ACTIVE)
        ->where('finished_at', '<', now()->subDays(90))
        ->toRawSql();

    $plan = collect(DB::select("explain {$sql}"))->pluck('QUERY PLAN')->implode("\n");

    expect($plan)->toContain('deployments_finished_at_index');
});
