<?php

namespace App\Actions\Deployments;

use App\Enums\DeploymentStatus;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;

/**
 * Cancels a run. One that hasn't started is cancelled on the spot; a running
 * one stops at its next check (between steps, or while a script runs).
 */
class CancelDeployment
{
    public function __invoke(Deployment $deployment): void
    {
        $cancelledWhileQueued = Deployment::query()
            ->whereKey($deployment->id)
            ->whereIn('status', [DeploymentStatus::Queued, DeploymentStatus::WaitingCi])
            ->update([
                'status' => DeploymentStatus::Cancelled,
                'cancel_requested_at' => now(),
                'finished_at' => now(),
                'outcome' => 'Cancelled before it started.',
            ]);

        if ($cancelledWhileQueued === 0) {
            Deployment::query()
                ->whereKey($deployment->id)
                ->whereIn('status', DeploymentStatus::ACTIVE)
                ->whereNull('cancel_requested_at')
                ->update(['cancel_requested_at' => now()]);
        }

        $deployment->refresh();
        rescue(fn () => DeploymentUpdated::dispatch($deployment->id, $deployment->project_id, $deployment->status->value), report: false);
    }
}
