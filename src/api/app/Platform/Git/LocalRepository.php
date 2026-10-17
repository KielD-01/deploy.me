<?php

namespace App\Platform\Git;

/**
 * Facts about a git repository on the Mac (mounted at the same path inside
 * the containers). Reads only; nothing here changes the repository.
 */
final class LocalRepository
{
    public function __construct(private readonly GitRunner $git) {}

    /**
     * A folder with .git in it: a directory, or a file for worktrees.
     */
    public static function isRepository(string $path): bool
    {
        return file_exists(rtrim($path, '/').'/.git');
    }

    /**
     * @return array{branch: ?string, commits: int, dirty: int, upstream: ?string, ahead: ?int, behind: ?int, head: ?array{sha: string, subject: string, date: string}, branches: list<string>, remotes: list<array{name: string, url: string}>}
     */
    public function inspect(string $path): array
    {
        $read = function (array $args) use ($path): ?string {
            $result = $this->git->run($path, $args, timeout: 30);

            return $result->successful() ? rtrim($result->output(), "\n") : null;
        };

        // Null on a detached HEAD.
        $branch = $read(['symbolic-ref', '--quiet', '--short', 'HEAD']);
        $commits = (int) ($read(['rev-list', '--count', 'HEAD']) ?? 0);

        $remotes = $this->remotes((string) $read(['remote', '-v']));

        // Compare with the branch's upstream, or else with the same branch on
        // a remote (origin first) when this clone knows about it.
        $upstream = $branch !== null ? $read(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}']) : null;
        $tracked = $upstream !== null;
        if ($upstream === null && $branch !== null) {
            foreach ($this->remoteNames($remotes) as $remote) {
                if ($read(['rev-parse', '--verify', '--quiet', "refs/remotes/{$remote}/{$branch}"]) !== null) {
                    $upstream = "{$remote}/{$branch}";
                    break;
                }
            }
        }

        $ahead = $behind = null;
        if ($upstream !== null && preg_match('/^(\d+)\s+(\d+)$/', (string) $read(['rev-list', '--left-right', '--count', "{$upstream}...HEAD"]), $counts)) {
            [$behind, $ahead] = [(int) $counts[1], (int) $counts[2]];
        }

        $head = null;
        if ($commits > 0 && ($log = $read(['log', '-1', '--format=%H%x1f%s%x1f%cI'])) !== null) {
            [$sha, $subject, $date] = array_pad(explode("\x1f", $log, 3), 3, '');
            $head = ['sha' => $sha, 'subject' => $subject, 'date' => $date];
        }

        return [
            'branch' => $branch,
            'commits' => $commits,
            'dirty' => $this->dirtyCount($path),
            'upstream' => $upstream,
            'tracked' => $tracked,
            'ahead' => $ahead,
            'behind' => $behind,
            'head' => $head,
            'branches' => array_values(array_filter(explode("\n", (string) $read(['for-each-ref', '--format=%(refname:short)', 'refs/heads'])))),
            'remotes' => $remotes,
        ];
    }

    /**
     * @param  list<array{name: string, url: string}>  $remotes
     * @return list<string>
     */
    private function remoteNames(array $remotes): array
    {
        $names = array_column($remotes, 'name');
        usort($names, fn (string $a, string $b) => ($b === 'origin') <=> ($a === 'origin'));

        return array_values(array_filter($names, fn (string $name) => preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) === 1));
    }

    /**
     * Changed, staged and untracked files, as `git status` lists them.
     */
    private function dirtyCount(string $path): int
    {
        $result = $this->git->run($path, ['status', '--porcelain=v1', '-z', '--untracked-files=normal'], timeout: 60);
        if (! $result->successful()) {
            return 0;
        }

        $entries = explode("\0", rtrim($result->output(), "\0"));
        $count = 0;

        for ($i = 0; $i < count($entries); $i++) {
            if ($entries[$i] === '') {
                continue;
            }
            $count++;
            // A rename or copy is followed by its source path.
            if (strpbrk(substr($entries[$i], 0, 2), 'RC') !== false) {
                $i++;
            }
        }

        return $count;
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function remotes(string $output): array
    {
        $remotes = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^(\S+)\s+(\S+)\s+\(fetch\)$/', $line, $match)) {
                $remotes[] = ['name' => $match[1], 'url' => GitRemote::redact($match[2])];
            }
        }

        return $remotes;
    }
}
