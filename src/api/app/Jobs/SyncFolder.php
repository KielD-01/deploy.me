<?php

namespace App\Jobs;

use App\Actions\Folders\RunFolderSync;
use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one folder sync on the syncs worker (the deploys connection, which
 * mounts the project folders read-write, with a queue of its own): a long
 * transfer never takes a deploy's worker.
 */
class SyncFolder implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Below the syncs worker's --timeout=3600.
    public int $timeout = 3500;

    public function __construct(public int $runId)
    {
        $this->onConnection('redis-deploys')->onQueue('syncs');
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
