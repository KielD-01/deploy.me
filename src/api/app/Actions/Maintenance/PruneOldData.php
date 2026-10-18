<?php

namespace App\Actions\Maintenance;

use App\Deploy\Logging\LogArchive;
use App\Enums\DeploymentStatus;
use App\Enums\SyncRunStatus;
use App\Models\AuditEntry;
use App\Models\Deployment;
use App\Models\RouteCheck;
use App\Models\ServerSnapshot;
use App\Models\SyncRun;
use App\Models\TargetHealthCheck;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The nightly tidy-up, by Settings → Schedules: finished runs' logs move from
 * the database into gzipped archives, old archives go, and so do old probe
 * history (and, after two days, a snapshot's process lists), old health checks, old audit entries and old folder syncs (each
 * mapping keeps its newest).
 */
class PruneOldData
{
    /**
     * @return array{archived: int, archives_removed: int, snapshots: int, processes: int, syncs: int, health_checks: int, route_checks: int, audit: int}
     */
    public function __invoke(): array
    {
        $archived = 0;

        Deployment::query()
            ->whereNotIn('status', DeploymentStatus::ACTIVE)
            ->where('finished_at', '<', now()->subDays(Settings::int('log_retention_days')))
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('deployment_logs')->whereColumn('deployment_logs.deployment_id', 'deployments.id'))
            // By id, not by page: an archived run no longer matches, and paging
            // by offset would skip as many runs as it had just archived.
            ->eachById(function (Deployment $deployment) use (&$archived): void {
                if (LogArchive::write($deployment)) {
                    DB::table('deployment_logs')->where('deployment_id', $deployment->id)->delete();
                    $archived++;
                }
            });

        $removed = 0;
        $cutoff = now()->subDays(Settings::int('archive_retention_days'))->getTimestamp();
        foreach (glob(storage_path('app/deploy-logs/*.log.gz')) ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        $snapshots = ServerSnapshot::query()
            ->where('captured_at', '<', now()->subDays(Settings::int('snapshot_retention_days')))
            ->delete();

        // The process lists are the bulk of a snapshot and only the last day's are offered.
        $processes = ServerSnapshot::query()
            ->whereNotNull('processes')
            ->where('captured_at', '<', now()->subDays(ServerSnapshot::PROCESS_DAYS))
            ->update(['processes' => null]);

        $syncs = SyncRun::query()
            ->whereNotIn('status', SyncRunStatus::ACTIVE)
            ->where('created_at', '<', now()->subDays(Settings::int('archive_retention_days')))
            ->whereNotIn('id', SyncRun::query()->selectRaw('max(id)')->groupBy('folder_mapping_id'))
            ->delete();

        $checks = TargetHealthCheck::query()
            ->where('checked_at', '<', now()->subDays(Settings::int('health_retention_days')))
            ->delete();

        $routeChecks = RouteCheck::query()
            ->where('checked_at', '<', now()->subDays(Settings::int('health_retention_days')))
            ->delete();

        // The audit log refuses DELETE unless the transaction says it's the
        // prune; the flag is transaction-local, so it can't leak past this.
        $audit = DB::transaction(function (): int {
            $sqlite = DB::getDriverName() === 'sqlite';

            if ($sqlite) {
                // SQLite (the slim flavour): a row the trigger looks for, gone
                // again before the commit, and with it on a rollback.
                DB::table('audit_log_pruning')->insertOrIgnore(['active' => true]);
            } else {
                DB::statement("select set_config('deployme.pruning', 'on', true)");
            }

            $deleted = AuditEntry::query()->where('occurred_at', '<', now()->subDays(Settings::int('audit_retention_days')))->delete();

            if ($sqlite) {
                DB::table('audit_log_pruning')->delete();
            }

            return $deleted;
        });

        return [
            'archived' => $archived, 'archives_removed' => $removed, 'snapshots' => $snapshots, 'processes' => $processes,
            'syncs' => $syncs, 'health_checks' => $checks, 'route_checks' => $routeChecks, 'audit' => $audit,
        ];
    }
}
