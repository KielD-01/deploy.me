<?php

namespace App\Deploy\Pipeline;

use App\Deploy\Pipeline\Steps\Activate;
use App\Deploy\Pipeline\Steps\ReleaseLease;
use App\Deploy\Pipeline\Steps\ResolveRelease;
use App\Deploy\Pipeline\Steps\RestoreRelease;
use App\Deploy\Pipeline\Steps\RollBack;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseStatus;
use App\Enums\StepStatus;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Release;
use App\Models\Target;
use Illuminate\Support\Collection;

/**
 * Whether a finished deploy can be retried from the step it stopped at,
 * reusing the release it left on the server, and from which step. It can
 * when it's a deploy that failed, rolled back, was cancelled or stalled; the
 * project deploys into releases/ and that release is still there; nothing
 * else went live since (what's live is what it replaced, or the release
 * itself, left live); the target is free; and no retry of it got going yet
 * (then that retry is the one to retry).
 */
final class ResumePlanner
{
    private const KINDS = [DeploymentKind::Deploy, DeploymentKind::Redeploy];

    private const STATUSES = [DeploymentStatus::Failed, DeploymentStatus::RolledBack, DeploymentStatus::Cancelled, DeploymentStatus::Stalled];

    // Cleanup steps: nothing to resume from.
    private const CLEANUP = [RollBack::KEY, ReleaseLease::KEY];

    // Before these finish there's no release to pick up.
    private const TOO_EARLY = [ResolveRelease::KEY, RestoreRelease::KEY];

    public static function for(Deployment $run): ?ResumePoint
    {
        $target = $run->target()->with('project')->first();

        if ($target === null) {
            return null;
        }

        return self::plan(
            $run,
            $target,
            $run->release,
            $run->steps()->get(),
            busy: $target->activeDeployment()->exists(),
            // A retry that never picked the release up (cancelled while queued) doesn't count.
            retried: $run->retries()->whereNotNull('release_id')->exists(),
        );
    }

    /**
     * @param  Collection<int, DeploymentStep>  $steps
     */
    public static function plan(Deployment $run, Target $target, ?Release $release, Collection $steps, bool $busy, bool $retried): ?ResumePoint
    {
        if (! in_array($run->kind, self::KINDS, true) || ! in_array($run->status, self::STATUSES, true)
            || $busy || $retried || $target->archived_at !== null || ! $target->project->versionable) {
            return null;
        }

        if ($release === null || ! $release->inReleasesFolder() || $release->pruned_at !== null || $release->status === ReleaseStatus::Pruned) {
            return null;
        }

        $live = $target->current_release_id === $release->id;

        if (! $live && $target->current_release_id !== $run->previous_release_id) {
            return null;
        }

        $main = $steps->reject(fn (DeploymentStep $step) => in_array($step->key, self::CLEANUP, true))->sortBy('position')->values();

        // Where it stopped: the step that failed or was cancelled, or the one
        // its worker was running when it died; failing that, the first step
        // it never got to.
        $point = $main->first(fn (DeploymentStep $step) => in_array($step->status, [StepStatus::Failed, StepStatus::Cancelled, StepStatus::Running], true))
            ?? $main->first(fn (DeploymentStep $step) => $step->status === StepStatus::Pending);

        if ($point === null || in_array($point->key, self::TOO_EARLY, true)) {
            return null;
        }

        $activate = $main->firstWhere('key', Activate::KEY);

        return new ResumePoint($point->key, $point->label, switchesBack: ! $live && $activate !== null && $point->position > $activate->position);
    }
}
