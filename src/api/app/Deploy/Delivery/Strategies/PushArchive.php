<?php

namespace App\Deploy\Delivery\Strategies;

use App\Deploy\Delivery\DeliveryStrategy;
use App\Deploy\Delivery\Verdict;
use App\Enums\DeliveryStrategyKey;
use App\Enums\ProjectSource;
use App\Models\Credential;
use App\Models\Project;
use App\Platform\Git\GitFailed;
use App\Platform\Git\GitRunner;
use App\Platform\Git\LocalRepository;
use App\Platform\Local\ProjectRoots;
use App\Support\Format;

/**
 * Ships one commit: exported from the repository on the Mac (or a mirror of
 * the GitHub repository), then uploaded.
 */
final class PushArchive implements DeliveryStrategy
{
    public function __construct(
        private readonly GitRunner $git,
        private readonly ProjectRoots $roots,
    ) {}

    public function key(): DeliveryStrategyKey
    {
        return DeliveryStrategyKey::PushArchive;
    }

    public function evaluate(Project $project, ?string $commit): Verdict
    {
        if ($project->source === ProjectSource::Github) {
            if (($project->credential ?? Credential::forOwner((string) $project->github_owner)) === null) {
                return Verdict::notApplicable("No GitHub token reaches {$project->github_owner}, so the repository can’t be fetched.");
            }

            return Verdict::applicable(
                $commit !== null
                    ? 'Commit '.substr($commit, 0, 7)." of {$project->githubFullName()}."
                    : "The latest commit on {$project->branch}, fetched from {$project->githubFullName()}.",
                $commit,
            );
        }

        $path = $this->roots->resolve((string) $project->local_path);

        if ($path === null) {
            return Verdict::notApplicable(Format::homePath($project->local_path).' isn’t there.');
        }

        if (! LocalRepository::isRepository($path)) {
            return Verdict::notApplicable('The folder isn’t a git repository.');
        }

        $ref = $commit ?? $project->branch;
        $sha = $this->git->run($path, ['rev-parse', '--verify', '--quiet', '--end-of-options', $ref.'^{commit}'], timeout: 30);

        if (! $sha->successful()) {
            return Verdict::notApplicable($commit !== null ? "Commit {$commit} isn’t in the repository." : "The branch {$project->branch} isn’t in the repository.");
        }

        $sha = trim($sha->output());
        $message = trim($this->git->run($path, ['log', '-1', '--format=%s', $sha], timeout: 30)->output());
        $dirty = $this->uncommitted($path);

        return Verdict::applicable(
            'Commit '.substr($sha, 0, 7).($commit === null ? " on {$project->branch}" : '').' from '.Format::homePath($project->local_path).'.'
                .($dirty > 0 ? " {$dirty} uncommitted ".($dirty === 1 ? 'change isn’t' : 'changes aren’t').' included.' : ''),
            $sha,
            $message,
        );
    }

    /**
     * How many tracked files differ from HEAD — only said in the verdict, since
     * uncommitted changes never ship. Read through the bind mount the repo is
     * seen by here, every file's inode and ctime differ from what the host wrote
     * into the index, so a plain `git status` re-hashes the whole tree and can't
     * save the result (warehouse: 29 s). Comparing size and mtime alone, which the
     * mount keeps, is 4 s; and a count that still doesn't come back is no reason
     * to stop a deploy.
     */
    private function uncommitted(string $path): int
    {
        try {
            $status = $this->git->run($path, [
                '-c', 'core.checkStat=minimal', '-c', 'core.trustctime=false', '--no-optional-locks',
                'status', '--porcelain=v1', '--untracked-files=no',
            ], timeout: 30);
        } catch (GitFailed) {
            return 0;
        }

        return $status->successful() ? count(array_filter(explode("\n", $status->output()))) : 0;
    }
}
