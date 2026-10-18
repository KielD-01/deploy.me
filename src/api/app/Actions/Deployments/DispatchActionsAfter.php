<?php

namespace App\Actions\Deployments;

use App\Deploy\DeploymentBusy;
use App\Deploy\DeploymentRefused;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\ProjectAction;
use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * When a run ends: a deploy (or redeploy) that succeeded or failed starts the
 * project's actions that run after one, and an action that one of those
 * started hands over to the next. A target takes one run at a time, so
 * they go one after the other, in the order of their keys, each carrying
 * the ones still to come.
 */
class DispatchActionsAfter
{
    public function __construct(private readonly StartAction $start) {}

    public function __invoke(Deployment $run): void
    {
        $target = Target::query()->with(['project', 'server', 'currentRelease'])->find($run->target_id);
        $user = $run->user_id !== null ? User::query()->find($run->user_id) : null;

        if ($target === null || $target->archived_at !== null) {
            return;
        }

        if ($run->isAction()) {
            $chain = array_values(array_filter((array) ($run->options['chain'] ?? []), 'is_int'));

            if ($chain !== []) {
                $this->startFirst($target, $chain, (int) ($run->options['after'] ?? 0), $user);
            }

            return;
        }

        if (! in_array($run->kind, [DeploymentKind::Deploy, DeploymentKind::Redeploy], true)) {
            return;
        }

        $outcome = match ($run->status) {
            DeploymentStatus::Succeeded => 'succeeded',
            DeploymentStatus::Failed, DeploymentStatus::RolledBack => 'failed',
            default => null,
        };

        if ($outcome === null) {
            return;
        }

        $ids = $target->ofProject()->actions()->get()
            ->filter(function (ProjectAction $action) use ($outcome, $target) {
                $definition = $action->definition();

                return $definition !== null && ! $definition->passkey() && $definition->allows($target)
                    && in_array($definition->afterDeploy(), [$outcome, 'either'], true);
            })
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            $this->startFirst($target, $ids, $run->id, $user);
        }
    }

    /**
     * Starts the first of the actions that can start, passing it the rest.
     * The run says whose deploy it followed.
     *
     * @param  list<int>  $ids
     */
    private function startFirst(Target $target, array $ids, int $after, ?User $user): void
    {
        while ($ids !== []) {
            $action = ProjectAction::query()->find(array_shift($ids));

            if ($action === null) {
                continue;
            }

            try {
                ($this->start)($action, $target, [], $user, 'after_deploy', ['after' => $after, 'chain' => $ids]);

                return;
            } catch (DeploymentBusy|DeploymentRefused $e) {
                Log::info("Action {$action->key} didn’t start after run #{$after}", ['reason' => $e->getMessage()]);
            }
        }
    }
}
