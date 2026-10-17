<?php

namespace App\Platform\GitHub;

use App\Enums\CiState;

/**
 * A branch's CI state from its workflow runs. Only the runs for one commit
 * count, one per workflow: any still going means running, any failed means
 * failing. That commit is the branch's head when the caller knows it, since
 * GitHub's runs list doesn't reliably put the newest first.
 */
final class CiStatus
{
    private const FAILED = ['failure', 'timed_out', 'action_required', 'startup_failure'];

    /**
     * @param  list<array<string, mixed>>  $runs  newest first, as GitHub lists them
     * @param  ?string  $sha  the branch head; the first run's commit when null
     * @return array{state: CiState, run: ?array<string, mixed>}
     */
    public static function fromRuns(array $runs, ?string $sha = null): array
    {
        if ($runs === []) {
            return ['state' => CiState::NoWorkflows, 'run' => null];
        }

        $sha ??= $runs[0]['head_sha'] ?? null;
        $latest = [];
        foreach ($runs as $run) {
            if (($run['head_sha'] ?? null) === $sha) {
                $latest[$run['workflow_id'] ?? $run['name'] ?? $run['id']] ??= $run;
            }
        }

        // Nothing has run for the head yet.
        if ($latest === []) {
            return ['state' => CiState::Unknown, 'run' => null];
        }

        $running = collect($latest)->first(fn (array $run) => ($run['status'] ?? 'completed') !== 'completed');
        $failed = collect($latest)->first(fn (array $run) => in_array($run['conclusion'] ?? null, self::FAILED, true));
        $passed = collect($latest)->first(fn (array $run) => ($run['conclusion'] ?? null) === 'success');

        $state = match (true) {
            $running !== null => CiState::Running,
            $failed !== null => CiState::Failing,
            $passed !== null => CiState::Passing,
            default => CiState::Unknown,
        };

        return ['state' => $state, 'run' => self::summary($running ?? $failed ?? $passed ?? reset($latest))];
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    public static function summary(array $run): array
    {
        return [
            'id' => $run['id'] ?? null,
            'name' => $run['name'] ?? null,
            'workflow' => basename((string) ($run['path'] ?? '')),
            'number' => $run['run_number'] ?? null,
            'status' => $run['status'] ?? null,
            'conclusion' => $run['conclusion'] ?? null,
            'event' => $run['event'] ?? null,
            'sha' => $run['head_sha'] ?? null,
            'url' => $run['html_url'] ?? null,
            'updated_at' => $run['updated_at'] ?? null,
        ];
    }
}
