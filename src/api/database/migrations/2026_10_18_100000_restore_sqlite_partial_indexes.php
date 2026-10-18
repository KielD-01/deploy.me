<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQLite only (the slim flavour); nothing happens on PostgreSQL.
 *
 * SQLite can't add a foreign key to a table it has, so Laravel rebuilds the
 * table instead (create a copy, move the rows, drop, rename) and re-creates its
 * indexes from what SQLite reports about them: the columns, not the WHERE. The
 * migrations that added the release and retry foreign keys did that to targets
 * and deployments, which left their partial unique indexes as plain unique ones
 * (one deployment per target, ever). This puts them back as they were made.
 *
 * A later migration that rebuilds one of these tables on SQLite (a foreign key
 * added or dropped, a column changed) must do the same again.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    public const INDEXES = [
        'targets_project_server_active_unique' => 'CREATE UNIQUE INDEX targets_project_server_active_unique ON targets (project_id, server_id) WHERE archived_at IS NULL',
        'targets_server_path_active_unique' => 'CREATE UNIQUE INDEX targets_server_path_active_unique ON targets (server_id, path) WHERE archived_at IS NULL',
        'deployments_one_active_per_target' => "CREATE UNIQUE INDEX deployments_one_active_per_target ON deployments (target_id) WHERE status IN ('queued', 'waiting_ci', 'running')",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::INDEXES as $name => $create) {
            DB::statement("drop index if exists {$name}");
            DB::statement($create);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the indexes are the ones the earlier migrations made.
    }
};
