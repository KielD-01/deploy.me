<?php

namespace App\Deploy\Delivery;

use App\Deploy\Pipeline\StepFailed;
use App\Models\Credential;
use App\Models\Project;
use App\Platform\Git\GitFailed;
use App\Platform\Git\GitRunner;
use Illuminate\Support\Facades\File;

/**
 * A bare mirror of a GitHub project's repository in storage/app/mirrors, so
 * commits can be exported without a folder on the Mac. The token only
 * reaches git through GIT_ASKPASS.
 */
final class GitHubMirror
{
    public function __construct(private readonly GitRunner $git) {}

    public function path(Project $project): string
    {
        return storage_path("app/mirrors/{$project->id}.git");
    }

    /**
     * Clones the mirror, or brings it up to date. A first clone can take
     * minutes: $while is called meanwhile (a deploy's heartbeat).
     *
     * @throws StepFailed
     */
    public function sync(Project $project, ?callable $while = null): string
    {
        $credential = $project->credential ?? Credential::forOwner((string) $project->github_owner);

        if ($credential === null) {
            throw new StepFailed("No GitHub token reaches {$project->github_owner}. Add one in Settings.");
        }

        $auth = ['username' => $credential->login, 'token' => $credential->token];
        $url = "https://github.com/{$project->github_owner}/{$project->github_repo}.git";
        $path = $this->path($project);

        try {
            if (! is_dir($path)) {
                File::ensureDirectoryExists(dirname($path));
                $this->git->mustRun(dirname($path), ['clone', '--mirror', '--quiet', $url, $path], $auth, timeout: 900, while: $while);
            } else {
                $this->git->mustRun($path, ['remote', 'set-url', 'origin', $url]);
                $this->git->mustRun($path, ['fetch', '--prune', '--quiet', 'origin'], $auth, timeout: 900, while: $while);
            }
        } catch (GitFailed $e) {
            throw new StepFailed("Couldn’t fetch {$project->githubFullName()} from GitHub: {$e->getMessage()}");
        }

        return $path;
    }
}
