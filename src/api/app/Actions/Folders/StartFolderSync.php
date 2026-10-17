<?php

namespace App\Actions\Folders;

use App\Events\FolderSyncUpdated;
use App\Jobs\SyncFolder;
use App\Models\FolderMapping;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues a sync: a dry run to preview, or the real thing. One at a time per
 * mapping.
 */
class StartFolderSync
{
    /**
     * @param  int|null  $maxDelete  the most files it may delete: what a preview showed, or the scheduled cap
     *
     * @throws FolderSyncBusy
     */
    public function __invoke(FolderMapping $mapping, bool $dryRun, ?User $user = null, string $trigger = 'manual', ?int $maxDelete = null): SyncRun
    {
        try {
            $run = DB::transaction(fn () => $mapping->runs()->create([
                'dry_run' => $dryRun,
                'user_id' => $user?->id,
                'trigger' => $trigger,
                'max_delete' => $mapping->delete_extraneous && ! $dryRun ? $maxDelete : null,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new FolderSyncBusy("{$mapping->name} is already syncing.");
        }

        SyncFolder::dispatch($run->id);
        rescue(fn () => FolderSyncUpdated::dispatch($mapping->server_id, $mapping->id), report: false);

        return $run;
    }
}
