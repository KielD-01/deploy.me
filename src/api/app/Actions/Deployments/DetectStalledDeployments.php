<?php

namespace App\Actions\Deployments;

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

    public function __invoke(): int
    {
        $stalled = Deployment::query()
            ->where('status', DeploymentStatus::Running)
            ->where('heartbeat_at', '<', now()->subSeconds(self::AFTER_SECONDS))
            ->get();

        foreach ($stalled as $deployment) {
            $deployment->update([
                'status' => DeploymentStatus::Stalled,
                'finished_at' => now(),
                'outcome' => 'The deploy worker stopped reporting. The folder may still be locked; the next run takes that lock over.',
            ]);
            $deployment->failUnfinishedRelease();

            rescue(fn () => DeploymentUpdated::dispatch($deployment->id, $deployment->project_id, $deployment->status->value), report: false);
        }

        return $stalled->count();
    }
}
