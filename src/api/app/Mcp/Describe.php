<?php

namespace App\Mcp;

use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Models\Target;
use Illuminate\Support\Carbon;

/**
 * The shapes the MCP tools answer with: what a person reads on the pages,
 * as plain arrays, with a link back to the page. Never a token, a password
 * or an env file's contents.
 */
final class Describe
{
    /**
     * @return array<string, mixed>
     */
    public static function server(Server $server): array
    {
        return [
            'slug' => $server->slug,
            'name' => $server->name,
            'ssh_alias' => $server->ssh_alias,
            'environment' => $server->env_tag?->value,
            'status' => $server->status?->value,
            'status_reason' => $server->status_reason,
            'last_seen_at' => self::time($server->last_seen_at),
            'last_error' => $server->last_error,
            'url' => route('servers.show', $server),
        ];
    }

    /**
     * The latest probe, without the full process lists.
     *
     * @return array<string, mixed>|null
     */
    public static function snapshot(?ServerSnapshot $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $top = fn (string $list, string $field) => collect($snapshot->processes[$list] ?? [])
            ->take(5)
            ->map(fn (array $process) => ['name' => $process['name'], 'container' => $process['container'] ?? null, $field => $process[$field] ?? null])
            ->all();

        return [
            'captured_at' => self::time($snapshot->captured_at),
            'cpu_percent' => $snapshot->cpu_usage,
            'load' => [$snapshot->load_1, $snapshot->load_5, $snapshot->load_15],
            'memory_used_percent' => $snapshot->memoryUsedPercent(),
            'disks' => collect($snapshot->disks ?? [])
                ->map(fn (array $disk) => [
                    'mount' => $disk['mount'],
                    'used_percent' => $disk['size'] > 0 ? round(100 * $disk['used'] / $disk['size'], 1) : null,
                    'available_bytes' => $disk['available'],
                ])
                ->all(),
            'stacks' => collect($snapshot->stacks ?? [])->map(fn (array $stack) => ['name' => $stack['Name'] ?? null, 'status' => $stack['Status'] ?? null])->all(),
            'busiest' => [
                'cpu' => $top('cpu', 'cpu'),
                'memory_bytes' => $top('mem', 'rss'),
            ],
            'uptime_seconds' => $snapshot->uptime,
            'ssh_latency_ms' => $snapshot->ssh_latency_ms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function project(Project $project): array
    {
        return [
            'slug' => $project->slug,
            'name' => $project->name,
            'source' => $project->sourceLabel(),
            'github' => $project->githubFullName(),
            'branch' => $project->branch,
            'framework' => $project->framework,
            'deploy_mode' => $project->deployModeLabel(),
            'docker' => (bool) $project->dockerized,
            'blue_green' => (bool) $project->blue_green,
            // In priority order: the first that applies to a commit ships it.
            'delivery_strategies' => collect($project->strategies())->filter(fn (array $strategy) => $strategy['enabled'])->map(fn (array $strategy) => $strategy['key']->value)->values()->all(),
            'auto_deploy' => (bool) $project->auto_deploy,
            'auto_rollback' => (bool) $project->auto_rollback,
            'health_url' => $project->health_url,
            'ci' => $project->ci_state?->value,
            'ci_checked_at' => self::time($project->ci_checked_at),
            'url' => route('projects.show', $project),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function target(Target $target): array
    {
        $live = $target->currentRelease;
        $last = $target->latestDeployment;

        return [
            'project' => $target->project->slug,
            'server' => $target->server->slug,
            'path' => $target->path,
            'health' => $target->health?->value,
            'health_detail' => $target->health_detail,
            'health_checked_at' => self::time($target->health_checked_at),
            'unhealthy_since' => self::time($target->unhealthy_since),
            'live_release' => $live !== null ? self::release($live, $target->project) : null,
            'last_run' => $last !== null ? ['run' => $last->id, 'kind' => $last->kind->value, 'status' => $last->status->value, 'finished_at' => self::time($last->finished_at)] : null,
            'needs_conversion' => $target->needsConversion(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function release(Release $release, Project $project): array
    {
        return [
            'name' => $release->name,
            'commit' => $release->commit_sha,
            'message' => $release->commit_message,
            'strategy' => $release->strategy?->value,
            'status' => $release->status->value,
            'color' => $release->color,
            'activated_at' => self::time($release->activated_at),
            'can_roll_back_to' => $release->canRollBackTo($project),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deployment(Deployment $deployment): array
    {
        return [
            'run' => $deployment->id,
            'kind' => $deployment->kind->value,
            'status' => $deployment->status->value,
            'project' => $deployment->project?->slug,
            'server' => $deployment->server?->slug,
            'trigger' => $deployment->trigger,
            'commit' => $deployment->commit_sha,
            'message' => $deployment->commit_message,
            'strategy' => $deployment->strategy,
            'outcome' => $deployment->outcome,
            'queued_at' => self::time($deployment->created_at),
            'started_at' => self::time($deployment->started_at),
            'finished_at' => self::time($deployment->finished_at),
            'duration' => $deployment->started_at !== null ? $deployment->durationLabel() : null,
            'url' => route('deployments.show', $deployment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function step(DeploymentStep $step): array
    {
        return [
            'key' => $step->key,
            'label' => $step->label,
            'status' => $step->status->value,
            'detail' => $step->detail,
            'exit_code' => $step->exit_code,
            'duration' => $step->durationLabel(),
        ];
    }

    public static function time(?Carbon $time): ?string
    {
        return $time?->toIso8601String();
    }
}
