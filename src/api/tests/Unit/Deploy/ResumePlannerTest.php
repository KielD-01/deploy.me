<?php

use App\Deploy\Pipeline\ResumePlanner;
use App\Deploy\Pipeline\ResumePoint;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\StepStatus;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Project;
use App\Models\Release;
use App\Models\Target;
use Illuminate\Support\Collection;

/**
 * A deploy's step rows: the ones before $stopped succeeded, $stopped has
 * $status, and the rest were skipped (or are still pending, when the run
 * never finished). Cleanup comes last.
 *
 * @return Collection<int, DeploymentStep>
 */
function plannerSteps(string $stopped, StepStatus $status, bool $finished = true): Collection
{
    $keys = ['resolve', 'preflight', 'lease', 'folders', 'upload', 'shared', 'env', 'pins', 'hooks_after_upload', 'login', 'pull', 'build',
        'backup', 'migrate', 'hooks_release', 'activate', 'recreate', 'health', 'hooks_after_activate', 'state', 'prune', 'rollback', 'unlock'];
    $reached = false;

    return collect($keys)->map(function (string $key, int $position) use ($stopped, $status, $finished, &$reached) {
        $reached = $reached || $key === $stopped;
        $state = match (true) {
            $key === $stopped => $status,
            ! $reached => StepStatus::Succeeded,
            default => $finished ? StepStatus::Skipped : StepStatus::Pending,
        };

        return (new DeploymentStep)->forceFill(['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'position' => $position, 'status' => $state]);
    });
}

/**
 * Run #20 deployed release 12 over release 11, on a target where 11 is live.
 */
function plannerAsk(array $run = [], array $release = [], array $target = [], bool $versionable = true, ?Collection $steps = null, bool $busy = false, bool $retried = false, bool $noRelease = false): ?ResumePoint
{
    $project = (new Project)->forceFill(['versionable' => $versionable]);
    $target = (new Target)->forceFill(['id' => 3, 'current_release_id' => 11, ...$target])->setRelation('project', $project);
    $release = (new Release)->forceFill(['id' => 12, 'name' => '20260911-120000', 'layout' => Release::IN_RELEASES, 'status' => 'failed', ...$release]);
    $run = (new Deployment)->forceFill(['id' => 20, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Failed, 'release_id' => 12, 'previous_release_id' => 11, ...$run]);

    return ResumePlanner::plan($run, $target, $noRelease ? null : $release, $steps ?? plannerSteps('upload', StepStatus::Failed), $busy, $retried);
}

it('resumes a failed deploy from the step that failed', function () {
    $point = plannerAsk();

    expect($point->key)->toBe('upload')
        ->and($point->label)->toBe('Upload')
        ->and($point->switchesBack)->toBeFalse();
});

it('switches back to a release that was rolled back before retrying its health check', function () {
    $point = plannerAsk(run: ['status' => DeploymentStatus::RolledBack], steps: plannerSteps('health', StepStatus::Failed));

    expect($point->key)->toBe('health')->and($point->switchesBack)->toBeTrue();
});

it('retries a release left live from where it failed, without switching', function () {
    $point = plannerAsk(release: ['status' => 'ready'], target: ['current_release_id' => 12], steps: plannerSteps('health', StepStatus::Failed));

    expect($point->key)->toBe('health')->and($point->switchesBack)->toBeFalse();
});

it('resumes a cancelled run from the step it was cancelled in', function () {
    expect(plannerAsk(run: ['status' => DeploymentStatus::Cancelled], steps: plannerSteps('migrate', StepStatus::Cancelled))->key)->toBe('migrate');
});

it('picks up a run whose worker died at the step it was running', function (DeploymentStatus $status) {
    expect(plannerAsk(run: ['status' => $status], steps: plannerSteps('build', StepStatus::Running, finished: false))->key)->toBe('build');
})->with([DeploymentStatus::Stalled, DeploymentStatus::Failed]);

it('picks up a run whose worker died between steps at the first one it never got to', function () {
    expect(plannerAsk(run: ['status' => DeploymentStatus::Stalled], steps: plannerSteps('build', StepStatus::Pending, finished: false))->key)->toBe('build');
});

it('says no when only a fresh deploy will do', function () {
    expect(plannerAsk())->not->toBeNull()
        ->and(plannerAsk(run: ['status' => DeploymentStatus::Succeeded]))->toBeNull()
        ->and(plannerAsk(run: ['kind' => DeploymentKind::Rollback]))->toBeNull()
        ->and(plannerAsk(run: ['kind' => DeploymentKind::Bootstrap]))->toBeNull()
        // In place: nothing but the project folder to go back to.
        ->and(plannerAsk(versionable: false))->toBeNull()
        ->and(plannerAsk(release: ['layout' => Release::IN_PLACE]))->toBeNull()
        ->and(plannerAsk(release: ['status' => 'pruned']))->toBeNull()
        ->and(plannerAsk(noRelease: true))->toBeNull()
        // Something else went live since.
        ->and(plannerAsk(target: ['current_release_id' => 13]))->toBeNull()
        ->and(plannerAsk(busy: true))->toBeNull()
        ->and(plannerAsk(retried: true))->toBeNull()
        // It failed before it had a release.
        ->and(plannerAsk(steps: plannerSteps('resolve', StepStatus::Failed)))->toBeNull();
});
