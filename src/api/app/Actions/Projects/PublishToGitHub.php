<?php

namespace App\Actions\Projects;

use App\Models\Credential;
use App\Platform\Git\GitFailed;
use App\Platform\Git\GitRunner;
use App\Platform\Git\LocalRepository;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;
use App\Platform\Local\ProjectRoots;

/**
 * Puts a local repository on GitHub: creates the repository and pushes the
 * current branch, or pushes to a GitHub remote it already has. Writes to the
 * repository (remote and upstream config), so it runs in the worker, which
 * mounts the project folders read-write.
 */
class PublishToGitHub
{
    public function __construct(
        private readonly GitRunner $git,
        private readonly ProjectRoots $roots,
    ) {}

    /**
     * @param  callable(string): void  $step  progress for the page
     * @return array{owner: string, repo: string, remote: string, branch: string, url: string}
     *
     * @throws GitHubException
     * @throws GitFailed
     */
    public function create(string $path, Credential $credential, string $owner, string $name, bool $private, callable $step): array
    {
        $path = $this->repository($path);
        $branch = $this->branch($path);

        $step("Creating {$owner}/{$name} on GitHub");
        $repository = GitHubClient::for($credential)->createRepository(
            $owner,
            $name,
            $private,
            organization: strcasecmp($owner, $credential->login) !== 0,
        );

        $remote = $this->freeRemoteName($path);
        $step("Adding it as the {$remote} remote");
        $this->git->mustRun($path, ['remote', 'add', $remote, (string) $repository['clone_url']]);

        $this->pushBranch($path, $remote, $branch, $credential, $step);

        return [
            'owner' => (string) ($repository['owner']['login'] ?? $owner),
            'repo' => (string) ($repository['name'] ?? $name),
            'remote' => $remote,
            'branch' => $branch,
            'url' => (string) ($repository['html_url'] ?? "https://github.com/{$owner}/{$name}"),
        ];
    }

    /**
     * Pushes the current branch to a remote the repository already has.
     *
     * @param  callable(string): void  $step
     * @return array{remote: string, branch: string}
     *
     * @throws GitFailed
     */
    public function push(string $path, string $remote, Credential $credential, callable $step): array
    {
        $path = $this->repository($path);

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $remote)) {
            throw new GitFailed("“{$remote}” isn’t a remote name.");
        }

        $branch = $this->branch($path);
        $this->pushBranch($path, $remote, $branch, $credential, $step);

        return ['remote' => $remote, 'branch' => $branch];
    }

    private function pushBranch(string $path, string $remote, string $branch, Credential $credential, callable $step): void
    {
        $step("Pushing {$branch} to {$remote}");

        $this->git->mustRun(
            $path,
            ['push', '--set-upstream', $remote, $branch],
            auth: ['username' => $credential->login, 'token' => $credential->token],
            timeout: 1500,
        );
    }

    private function repository(string $path): string
    {
        $real = $this->roots->resolve($path);

        if ($real === null || ! LocalRepository::isRepository($real)) {
            throw new GitFailed('That folder isn’t a git repository inside your project folders.');
        }

        return $real;
    }

    private function branch(string $path): string
    {
        $branch = trim($this->git->run($path, ['symbolic-ref', '--quiet', '--short', 'HEAD'])->output());

        if ($branch === '') {
            throw new GitFailed('No branch is checked out (detached HEAD). Check out the branch to push first.');
        }

        if ((int) trim($this->git->run($path, ['rev-list', '--count', 'HEAD'])->output()) === 0) {
            throw new GitFailed('The repository has no commits yet. Commit something first.');
        }

        return $branch;
    }

    private function freeRemoteName(string $path): string
    {
        $remotes = array_filter(explode("\n", $this->git->run($path, ['remote'])->output()));

        foreach (['origin', 'github'] as $name) {
            if (! in_array($name, $remotes, true)) {
                return $name;
            }
        }

        return 'github-'.bin2hex(random_bytes(2));
    }
}
