<?php

namespace App\Jobs;

use App\Actions\Folders\RunFolderSync;
use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one folder sync on the deploys worker: long transfers fit its timeout,
 * and it mounts the project folders read-write.
 */
class SyncFolder implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Below the deploys worker's --timeout=3600.
    public int $timeout = 3500;

    public function __construct(public int $runId)
    {
        $this->onConnection('redis-deploys')->onQueue('deploys');
    }

    public function handle(RunFolderSync $sync): void
    {
        $run = SyncRun::query()->find($this->runId);

        // Cancelled while it waited in the queue.
        if ($run === null || $run->status !== SyncRunStatus::Queued) {
            return;
        }

        $sync($run);
    }

    public function failed(?Throwable $exception): void
    {
        SyncRun::query()
            ->whereKey($this->runId)
            ->whereIn('status', SyncRunStatus::ACTIVE)
            ->update([
                'status' => SyncRunStatus::Failed,
                'finished_at' => now(),
                'error' => 'The worker stopped: '.($exception?->getMessage() ?: 'no reason given').'.',
            ]);
    }
}
