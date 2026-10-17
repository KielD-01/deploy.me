<?php

namespace App\Actions\Deployments;

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;

/**
 * A running deploy reports every few seconds, even while a command is quiet.
 * Two minutes of silence means its worker is gone: the run is stalled, and
 * the release it was building failed.
 */
class DetectStalledDeployments
{
    public const AFTER_SECONDS = 120;

    public const LOST_WAIT_MINUTES = 10;

    public function __invoke(): int
    {
        $stalled = Deployment::query()
            ->where('status', DeploymentStatus::Running)
            ->where('heartbeat_at', '<', now()->subSeconds(self::AFTER_SECONDS))
            ->get();

        // A run waiting for CI past its hour that nobody has picked up for a
        // while: its re-check was lost (the queue's store flushed, say), and it
        // would only fail once picked up. Left waiting, it blocks the target.
        $lost = Deployment::query()
            ->where('status', DeploymentStatus::WaitingCi)
            ->where('created_at', '<', now()->subMinutes(PipelineRunner::MAX_WAIT_MINUTES + self::LOST_WAIT_MINUTES))
            ->where('heartbeat_at', '<', now()->subMinutes(self::LOST_WAIT_MINUTES))
            ->get();

        foreach ($lost as $deployment) {
            $deployment->update([
                'status' => DeploymentStatus::Stalled,
                'finished_at' => now(),
                'outcome' => 'It waited for CI for over an hour, and nothing checked on it again.',
            ]);
            rescue(fn () => DeploymentUpdated::dispatch($deployment->id, $deployment->project_id, $deployment->status->value), report: false);
        }

        foreach ($stalled as $deployment) {
            $deployment->update([
                'status' => DeploymentStatus::Stalled,
                'finished_at' => now(),
                'outcome' => 'The deploy worker stopped reporting. The folder may still be locked; the next run takes that lock over.',
            ]);
            $deployment->failUnfinishedRelease();

            rescue(fn () => DeploymentUpdated::dispatch($deployment->id, $deployment->project_id, $deployment->status->value), report: false);
        }

        return $stalled->count() + $lost->count();
    }
}
