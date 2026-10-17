<?php

namespace App\Jobs;

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs one deployment on the deploys worker. Never retried by the queue: a
 * retry is a new run you start on purpose.
 */
class RunDeployment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Below the deploys worker's --timeout (supervisor/conf.d/queues.conf), and
    // above every step's own budget (Pipeline\StepTimeout::MAX), so a step that
    // runs long fails with a reason instead of taking the worker down with it.
    public const TIMEOUT = 7000;

    public int $timeout = self::TIMEOUT;

    public function __construct(public int $deploymentId)
    {
        $this->onConnection('redis-deploys')->onQueue('deploys');
    }

    public function handle(PipelineRunner $runner): void
    {
        // A run waiting for CI can be in the queue twice ("Deploy now" beside
        // the 20 s re-check), and it stays waiting until its commit resolves:
        // the second worker to pick it up leaves it to the first.
        $lock = Cache::lock("deployme:run-deployment:{$this->deploymentId}", self::TIMEOUT + 300);

        if (! $lock->get()) {
            return;
        }

        try {
            $deployment = Deployment::query()->find($this->deploymentId);

            // Cancelled while it waited in the queue (or for CI).
            if ($deployment === null || ! in_array($deployment->status, [DeploymentStatus::Queued, DeploymentStatus::WaitingCi], true)) {
                return;
            }

            $runner->run($deployment);
        } finally {
            $lock->release();
        }
    }

    /**
     * The worker died or timed out mid-run: record it, so the target isn't
     * left looking busy, and the release it was building as failed.
     */
    public function failed(?Throwable $exception): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);

        if ($deployment === null || ! $deployment->isActive()) {
            return;
        }

        $deployment->update([
            'status' => DeploymentStatus::Failed,
            'finished_at' => now(),
            'outcome' => 'The deploy worker stopped: '.($exception?->getMessage() ?: 'no reason given').'.',
            'log_lines' => $deployment->loggedLines(),
        ]);
        $deployment->failUnfinishedRelease();

        rescue(fn () => DeploymentUpdated::dispatch($deployment->id, $deployment->project_id, $deployment->status->value), report: false);
    }
}
