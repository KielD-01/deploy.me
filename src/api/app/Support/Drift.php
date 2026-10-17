<?php

namespace App\Support;

use App\Deploy\Delivery\GitHubMirror;
use App\Enums\ProjectSource;
use App\Models\Project;
use App\Platform\Git\GitRunner;
use App\Platform\Git\LocalRepository;
use App\Platform\Local\ProjectRoots;

/**
 * What's newest on a project's branch, and how far a deployed commit is
 * behind it: from the repository on the Mac (or the GitHub mirror), else from
 * the last CI poll.
 *
 * It runs git (three processes for a local repository), so it is the
 * RefreshDrift job that asks, and the targets that keep the answer
 * (`Target::drift()`); nothing renders a page by calling it.
 */
final class Drift
{
    /**
     * @return array{head: ?string, behind: ?int, dirty: ?int}
     */
    public static function measure(Project $project, ?string $deployed): array
    {
        $repository = self::repository($project);

        if ($repository === null) {
            return self::fromCi($project, $deployed);
        }

        $git = app(GitRunner::class);
        $result = $git->run($repository, ['rev-parse', '--verify', '--quiet', '--end-of-options', $project->branch.'^{commit}'], timeout: 15);
        $head = $result->successful() ? trim($result->output()) : null;

        $behind = null;
        if ($head !== null && $head === $deployed) {
            $behind = 0;
        } elseif ($head !== null && $deployed !== null && preg_match('/^[0-9a-f]{40}$/', $deployed) === 1) {
            $count = $git->run($repository, ['rev-list', '--count', "{$deployed}..{$head}"], timeout: 15);
            $behind = $count->successful() ? (int) trim($count->output()) : null;
        }

        $dirty = null;
        if ($project->source === ProjectSource::Local) {
            $status = $git->run($repository, ['status', '--porcelain=v1'], timeout: 15);
            $dirty = $status->successful() ? count(array_filter(explode("\n", $status->output()))) : null;
        }

        return ['head' => $head, 'behind' => $behind, 'dirty' => $dirty];
    }

    /**
     * Without a repository to ask, the last CI poll's commit stands for the
     * head. Needs no git, so it is also what a target shows before its first
     * measurement.
     *
     * @return array{head: ?string, behind: ?int, dirty: ?int}
     */
    public static function fromCi(Project $project, ?string $deployed): array
    {
        $head = $project->ci_run['sha'] ?? null;

        return ['head' => $head, 'behind' => $head !== null && $head === $deployed ? 0 : null, 'dirty' => null];
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
}
