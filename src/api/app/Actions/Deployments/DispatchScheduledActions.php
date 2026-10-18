<?php

namespace App\Actions\Deployments;

use App\Deploy\DeploymentBusy;
use App\Deploy\DeploymentRefused;
use App\Models\Project;
use App\Models\ProjectAction;
use Illuminate\Support\Facades\Log;

/**
 * Each minute: the actions whose schedule came due since the scheduler last
 * dealt with them start on their target. One that finds the target busy (a
 * deploy, another action) tries again the next minute, for up to an hour;
 * after that, that time is let go and the next one waits for its turn.
 */
class DispatchScheduledActions
{
    // How long a scheduled run that couldn't start keeps trying.
    public const PATIENCE_MINUTES = 60;

    public function __construct(private readonly StartAction $start) {}

    /**
     * @return int how many runs it started
     */
    public function __invoke(): int
    {
        $now = now()->startOfMinute();
        $started = 0;

        $actions = ProjectAction::query()
            ->whereNull('errors')
            ->whereHas('project', fn ($query) => $query->whereNull('archived_at'))
            ->with('project')
            ->get();

        foreach ($actions as $action) {
            $definition = $action->definition();
            $due = $definition?->lastDue($now);

            if ($definition === null || $due === null || $definition->passkey()) {
                continue;
            }

            // Seen for the first time (just made, or loaded): from now on.
            if ($action->scheduled_through === null) {
                $action->forceFill(['scheduled_through' => $now])->save();

                continue;
            }

            if ($due->lte($action->scheduled_through)) {
                continue;
            }

            /** @var Project $project */
            $project = $action->project;
            $target = $definition->scheduledTarget($project);

            try {
                if ($target === null) {
                    throw new DeploymentRefused('it has no target to run on');
                }

                ($this->start)($action, $target, [], null, 'schedule');
                $started++;
            } catch (DeploymentBusy $e) {
                // The target is busy: try again next minute, while it's not too late.
                if ($due->gt($now->copy()->subMinutes(self::PATIENCE_MINUTES))) {
                    continue;
                }

                Log::info("Scheduled action {$action->key} gave up on its {$due->toIso8601String()} run", ['reason' => $e->getMessage()]);
            } catch (DeploymentRefused $e) {
                Log::info("Scheduled action {$action->key} didn’t start", ['reason' => $e->getMessage()]);
            }

            $action->forceFill(['scheduled_through' => $due])->save();
        }

        return $started;
    }
}
