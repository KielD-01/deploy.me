<?php

namespace App\Actions\Projects;

use App\Actions\Deployments\StartDeployment;
use App\Deploy\DeploymentBusy;
use App\Enums\CiState;
use App\Enums\DeploymentKind;
use App\Events\ProjectCiUpdated;
use App\Models\Credential;
use App\Models\Project;
use App\Models\Target;
use App\Platform\GitHub\CiStatus;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refreshes a project's GitHub Actions state for its deploy branch. The
 * ETag from the last answer makes an unchanged poll free.
 */
class PollCi
{
    public function __invoke(Project $project): void
    {
        $before = $project->ci_state;

        if (! $project->hasGitHub()) {
            $project->forceFill(['ci_state' => CiState::NoRemote, 'ci_checked_at' => now()])->save();
            $this->announce($project, $before);

            return;
        }

        $credential = $project->credential ?? Credential::forOwner($project->github_owner);

        if ($credential === null) {
            $project->forceFill(['ci_state' => CiState::Unknown, 'ci_checked_at' => now()])->save();
            $this->announce($project, $before);

            return;
        }

        $github = GitHubClient::for($credential);
        $head = null;

        try {
            $answer = $github->workflowRuns($project->github_owner, $project->github_repo, $project->branch, $project->ci_etag);

            if ($answer['changed']) {
                $head = $github->branchHead($project->github_owner, $project->github_repo, $project->branch);
                $ci = $head === null
                    ? ['state' => CiState::Unknown, 'run' => null]
                    : CiStatus::fromRuns($this->runsFor($github, $project, $answer['runs'], $head), $head);
            }
        } catch (GitHubException $e) {
            Log::info('CI poll failed', ['project' => $project->id, 'error' => $e->getMessage()]);
            $project->forceFill(['ci_checked_at' => now()])->save();

            return;
        }

        if ($answer['changed']) {
            $project->forceFill(['ci_state' => $ci['state'], 'ci_run' => $ci['run'], 'ci_etag' => $answer['etag']]);
        }

        $project->forceFill(['ci_checked_at' => now()])->save();

        if ($answer['changed']) {
            $this->announce($project, null);

            if ($project->auto_deploy && $project->ci_state === CiState::Passing && $head !== null && ($project->ci_run['sha'] ?? null) === $head) {
                $this->deployPassing($github, $project, $head);
            }
        }
    }

    /**
     * The runs for the branch head. GitHub's list of the branch's runs has
     * come back with months-old runs first, or empty, so the head's runs are
     * asked for by commit when the list doesn't have them.
     *
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function runsFor(GitHubClient $github, Project $project, array $runs, string $head): array
    {
        $listed = array_values(array_filter($runs, fn (array $run) => ($run['head_sha'] ?? null) === $head));

        return $listed ?: ($github->runsForCommit($project->github_owner, $project->github_repo, $head) ?: $runs);
    }

    /**
     * CI passed on the branch head: deploy it to every target that hasn't
     * had it yet and isn't already running something newer.
     */
    private function deployPassing(GitHubClient $github, Project $project, string $sha): void
    {
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            return;
        }

        foreach ($project->targets()->active()->with('currentRelease')->get() as $target) {
            $already = $target->deployments()
                ->whereIn('kind', [DeploymentKind::Deploy, DeploymentKind::Redeploy])
                ->where(fn ($query) => $query->where('commit_sha', $sha)->orWhere('options->commit', $sha))
                ->exists();

            if ($already) {
                continue;
            }

            try {
                if (! $this->movesForward($github, $project, $target, $sha)) {
                    continue;
                }
            } catch (GitHubException $e) {
                // Forget the ETag so the next poll gets a full answer and asks again.
                Log::info('CI auto-deploy check failed', ['project' => $project->id, 'target' => $target->id, 'error' => $e->getMessage()]);
                $project->forceFill(['ci_etag' => null])->save();

                return;
            }

            try {
                app(StartDeployment::class)($target, DeploymentKind::Deploy, null, ['commit' => $sha], 'ci');
            } catch (DeploymentBusy) {
                // The run that's going finishes first; the next green build deploys.
            }
        }
    }

    /**
     * Whether $sha is newer than what the target runs. An automatic deploy
     * never puts back a commit the live release already contains: that's a
     * rollback, and only a person starts one. A head that diverged from the
     * live commit (the branch was rewritten) still goes, as does one GitHub
     * can't compare because it no longer knows the live commit.
     */
    private function movesForward(GitHubClient $github, Project $project, Target $target, string $sha): bool
    {
        $live = $target->currentRelease?->commit_sha;

        if ($live === null || preg_match('/^[0-9a-f]{40}$/', $live) !== 1) {
            return true;
        }

        $status = $live === $sha ? 'identical' : $github->compare($project->github_owner, $project->github_repo, $live, $sha);

        if ($status === 'behind' || $status === 'identical') {
            Log::warning('CI auto-deploy refused: the commit is not newer than the live release', [
                'project' => $project->id, 'target' => $target->id, 'commit' => $sha, 'live' => $live, 'status' => $status,
            ]);

            return false;
        }

        return true;
    }

    private function announce(Project $project, ?CiState $before): void
    {
        if ($before !== null && $before === $project->ci_state) {
            return;
        }

        try {
            ProjectCiUpdated::dispatch($project->id, $project->ci_state?->value);
        } catch (Throwable $e) {
            Log::warning('Could not broadcast ProjectCiUpdated', ['project' => $project->id, 'error' => $e->getMessage()]);
        }
    }
}
