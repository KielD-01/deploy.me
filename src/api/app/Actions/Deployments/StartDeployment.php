<?php

namespace App\Actions\Deployments;

use App\Deploy\DeploymentBusy;
use App\Enums\DeploymentKind;
use App\Events\DeploymentUpdated;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Target;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues a run against a target. One run per target at a time: the database
 * refuses a second active one.
 */
class StartDeployment
{
    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $attributes  more of the run's columns: a retry's retry_of_id, resume_from and strategy
     *
     * @throws DeploymentBusy
     */
    public function __invoke(Target $target, DeploymentKind $kind, ?User $user = null, array $options = [], string $trigger = 'manual', array $attributes = []): Deployment
    {
        try {
            // Its own (nested) transaction, so a refused insert doesn't poison
            // a transaction the caller has open.
            $deployment = DB::transaction(fn () => Deployment::query()->create([
                ...$attributes,
                'target_id' => $target->id,
                'project_id' => $target->project_id,
                'server_id' => $target->server_id,
                'user_id' => $user?->id,
                'kind' => $kind,
                'trigger' => $trigger,
                'options' => $options,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new DeploymentBusy('A deploy is already queued or running for this target.');
        }

        rescue(fn () => DeploymentUpdated::dispatch($deployment->id, $deployment->project_id, $deployment->status->value), report: false);
        RunDeployment::dispatch($deployment->id);

        return $deployment;
    }
}
