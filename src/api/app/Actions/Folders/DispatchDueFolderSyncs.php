<?php

namespace App\Actions\Folders;

use App\Models\FolderMapping;

/**
 * Starts the scheduled syncs that are due. A scheduled sync deletes at most
 * deployme.sync.scheduled_max_delete files; anything bigger waits for a
 * preview you apply.
 */
class DispatchDueFolderSyncs
{
    public function __construct(private readonly StartFolderSync $start) {}

    public function __invoke(): int
    {
        $due = FolderMapping::query()
            ->whereNotNull('schedule_interval')
            ->where('next_run_at', '<=', now())
            ->whereHas('server', fn ($query) => $query->whereNull('archived_at'))
            ->get();

        $started = 0;

        foreach ($due as $mapping) {
            $mapping->update(['next_run_at' => now()->addSeconds($mapping->schedule_interval)]);

            try {
                ($this->start)($mapping, dryRun: false, trigger: 'schedule', maxDelete: (int) config('deployme.sync.scheduled_max_delete', 100));
                $started++;
            } catch (FolderSyncBusy) {
                // Still going from last time; the next slot picks it up.
            }
        }

        return $started;
    }
}
