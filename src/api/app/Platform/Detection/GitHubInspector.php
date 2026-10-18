<?php

namespace App\Platform\Detection;

use App\Deploy\Actions\RepoActions;
use App\Enums\CiState;
use App\Platform\GitHub\CiStatus;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;

/**
 * Everything an import needs to know about a GitHub repository, read
 * through the API: nothing is cloned.
 */
final class GitHubInspector
{
    public function __construct(private readonly ProjectDetector $detector) {}

    /**
     * @return array<string, mixed> see Detection
     *
     * @throws GitHubException
     */
    public function inspect(GitHubClient $github, string $owner, string $repo, ?string $branch = null): array
    {
        $info = $github->repository($owner, $repo);
        $owner = (string) ($info['owner']['login'] ?? $owner);
        $repo = (string) ($info['name'] ?? $repo);
        $branch = $branch ?: (string) ($info['default_branch'] ?? 'main');
        $commits = $github->commits($owner, $repo, $branch);

        try {
            $ci = CiStatus::fromRuns($github->workflowRuns($owner, $repo, $branch)['runs'], $commits['head']['sha'] ?? null);
        } catch (GitHubException) {
            $ci = ['state' => CiState::Unknown, 'run' => null];
        }

        $about = [
            'owner' => $owner,
            'repo' => $repo,
            'id' => $info['id'] ?? null,
            'private' => (bool) ($info['private'] ?? true),
            'default_branch' => (string) ($info['default_branch'] ?? $branch),
            'branch' => $branch,
            'commits' => $commits['count'],
            'head' => $commits['head'],
            'pushed_at' => $info['pushed_at'] ?? null,
            'language' => $info['language'] ?? null,
            'remote' => null,
            'transport' => 'https',
            'alias' => null,
            'ci' => ['state' => $ci['state']->value, 'run' => $ci['run']],
        ];
        $files = new GitHubFiles($github, $owner, $repo, $branch, (int) config('deployme.detection.depth', 3));

        return [
            'v' => 1,
            'source' => 'github',
            'git' => null,
            'remotes' => [],
            'github' => $about,
        ] + $this->detector->detect($files) + [
            // The project's actions (.deployme/actions), read with the rest: RepoActions stores them.
            'action_files' => RepoActions::files($files),
        ];
    }
}
