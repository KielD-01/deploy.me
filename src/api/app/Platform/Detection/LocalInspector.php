<?php

namespace App\Platform\Detection;

use App\Platform\Git\GitRemote;
use App\Platform\Git\LocalRepository;
use App\Platform\Ssh\SshConfigReader;

/**
 * Everything an import needs to know about a local folder: git state, the
 * GitHub repository behind its remotes, and what's in its files.
 */
final class LocalInspector
{
    public function __construct(
        private readonly LocalRepository $repository,
        private readonly ProjectDetector $detector,
        private readonly SshConfigReader $sshConfig,
    ) {}

    /**
     * @return array<string, mixed> see Detection
     */
    public function inspect(string $path): array
    {
        $git = null;
        $remotes = [];
        $github = null;

        if (LocalRepository::isRepository($path)) {
            $git = $this->repository->inspect($path);
            $remotes = $git['remotes'];
            unset($git['remotes']);
            $github = $this->github($remotes);
        }

        return [
            'v' => 1,
            'source' => 'local',
            'path' => $path,
            'git' => $git,
            'remotes' => $remotes,
            'github' => $github,
        ] + $this->detector->detect(new LocalFiles($path, (int) config('deployme.detection.depth', 3)));
    }

    /**
     * The GitHub repository behind the remotes, origin first. Remotes may use
     * an ~/.ssh/config alias for github.com (gh-work:owner/repo).
     *
     * @param  list<array{name: string, url: string}>  $remotes
     * @return array{owner: string, repo: string, remote: string, transport: string, alias: ?string}|null
     */
    private function github(array $remotes): ?array
    {
        usort($remotes, fn (array $a, array $b) => ($b['name'] === 'origin') <=> ($a['name'] === 'origin'));

        $isGitHubAlias = function (string $host): bool {
            $hostName = $this->sshConfig->find($host)?->hostName;

            return $hostName !== null && preg_match('/^(ssh\.)?github\.com$/i', $hostName) === 1;
        };

        foreach ($remotes as $remote) {
            $parsed = GitRemote::parse($remote['url'], $isGitHubAlias);

            if ($parsed !== null) {
                return [
                    'owner' => $parsed->owner,
                    'repo' => $parsed->repo,
                    'remote' => $remote['name'],
                    'transport' => $parsed->transport,
                    'alias' => $parsed->alias,
                ];
            }
        }

        return null;
    }
}
