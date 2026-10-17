<?php

namespace App\Support;

use App\Deploy\Delivery\GitHubMirror;
use App\Enums\ProjectSource;
use App\Models\Credential;
use App\Models\Project;
use App\Platform\Git\GitRunner;
use App\Platform\Git\LocalRepository;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;
use App\Platform\Local\ProjectRoots;
use Illuminate\Support\Carbon;

/**
 * A project's branch, newest commit first, and whether each adds a migration:
 * from the repository on the Mac, the GitHub mirror, or (no mirror yet) the
 * branch head from the API. The deploy dialog, the Console shell and its git
 * pane all read it.
 */
final class BranchCommits
{
    /**
     * @return list<array{sha: string, subject: string, date: ?Carbon, migration: bool}>
     */
    public static function recent(Project $project, int $limit = 12): array
    {
        $repository = self::repository($project);

        if ($repository !== null) {
            return self::fromGit($repository, $project->branch, $limit);
        }

        if ($project->source === ProjectSource::Github && ($credential = $project->credential ?? Credential::forOwner((string) $project->github_owner)) !== null) {
            try {
                $head = GitHubClient::for($credential)->commits((string) $project->github_owner, (string) $project->github_repo, $project->branch)['head'];
            } catch (GitHubException) {
                return [];
            }

            return $head !== null ? [[
                'sha' => $head['sha'],
                'subject' => $head['subject'],
                'date' => $head['date'] !== null ? Carbon::parse($head['date']) : null,
                'migration' => false,
            ]] : [];
        }

        return [];
    }

    private static function repository(Project $project): ?string
    {
        if ($project->source === ProjectSource::Local) {
            $path = app(ProjectRoots::class)->resolve((string) $project->local_path);

            return $path !== null && LocalRepository::isRepository($path) ? $path : null;
        }

        $mirror = app(GitHubMirror::class)->path($project);

        return is_dir($mirror) ? $mirror : null;
    }

    /**
     * @return list<array{sha: string, subject: string, date: ?Carbon, migration: bool}>
     */
    private static function fromGit(string $repository, string $branch, int $limit): array
    {
        $log = app(GitRunner::class)->run($repository, [
            'log', '-n', (string) $limit, '--format=%x1e%H%x1f%s%x1f%cI', '--name-status', '--end-of-options', $branch,
        ], timeout: 30);

        if (! $log->successful()) {
            return [];
        }

        $commits = [];

        foreach (array_filter(explode("\x1e", $log->output()), fn (string $record) => trim($record) !== '') as $record) {
            $lines = explode("\n", trim($record));
            [$sha, $subject, $date] = array_pad(explode("\x1f", (string) array_shift($lines), 3), 3, '');
            $migration = collect($lines)->contains(fn (string $line) => preg_match('#^A\t(.*/)?database/migrations/[^/]+\.php$#', $line) === 1);

            $commits[] = ['sha' => $sha, 'subject' => $subject, 'date' => $date !== '' ? Carbon::parse($date) : null, 'migration' => $migration];
        }

        return $commits;
    }
}
