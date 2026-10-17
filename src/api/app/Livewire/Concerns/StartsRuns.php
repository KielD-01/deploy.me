<?php

namespace App\Livewire\Concerns;

use App\Actions\Deployments\StartDeployment;
use App\Deploy\DeploymentBusy;
use App\Deploy\DeploymentRefused;
use App\Enums\DeploymentKind;
use App\Models\Target;

/**
 * Pages that start a run and go to it: what StartDeployment refuses (a run
 * already going, a folder to convert first) shows on the page as $problem.
 */
trait StartsRuns
{
    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $attributes
     */
    protected function startRun(StartDeployment $start, Target $target, DeploymentKind $kind, array $options = [], string $trigger = 'manual', array $attributes = []): void
    {
        try {
            $run = $start($target, $kind, auth()->user(), $options, $trigger, $attributes);
        } catch (DeploymentBusy|DeploymentRefused $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->redirectRoute('deployments.show', $run, navigate: true);
    }
}
