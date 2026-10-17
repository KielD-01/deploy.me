<?php

namespace App\Support;

use App\Enums\CiState;
use App\Models\Project;
use App\Models\Target;

/**
 * A commit CI has already passed on that no target of the project is running:
 * the build a deploy would pick up right now. It reads the last CI poll and
 * the targets' live releases, so calling it costs nothing.
 */
final class GreenBuild
{
    /**
     * @param  iterable<Target>  $targets  the project's live targets, with their current release and server
     * @return array{sha: string, short: string, workflow: ?string, number: ?int, url: ?string, servers: list<string>}|null
     */
    public static function for(Project $project, iterable $targets): ?array
    {
        $sha = (string) ($project->ci_run['sha'] ?? '');

        if ($project->ci_state !== CiState::Passing || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            return null;
        }

        $servers = [];

        foreach ($targets as $target) {
            // A target with nothing live, or one whose release came from no
            // commit we know, can't be called behind.
            $live = $target->currentRelease?->commit_sha;

            if ($live !== null && $live !== $sha) {
                $servers[] = $target->server->name;
            }
        }

        return $servers === [] ? null : [
            'sha' => $sha,
            'short' => substr($sha, 0, 7),
            'workflow' => $project->ci_run['name'] ?? $project->ci_run['workflow'] ?? null,
            'number' => $project->ci_run['number'] ?? null,
            'url' => $project->ci_run['url'] ?? null,
            'servers' => $servers,
        ];
    }
}
