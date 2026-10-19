<?php

namespace App\Actions\Transfer;

use App\Enums\ReleaseStatus;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Release;
use App\Models\Target;
use App\Support\Format;
use App\Support\Transfer\TransferCode;
use App\Support\Updates;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * What another deploy.me needs to take over projects: their settings, env
 * files, hooks and actions, and each target as a server alias and a folder,
 * with the releases still on it (so it knows what's live and what a rollback
 * goes back to). No runs, logs, CI state or GitHub tokens: the other machine
 * adds its own tokens, and finds the servers in its own ~/.ssh/config.
 */
class ExportProjects
{
    public const PROJECT_FIELDS = [
        'name', 'slug', 'source', 'local_path', 'git_remote', 'github_owner', 'github_repo', 'github_repo_id',
        'branch', 'framework', 'versionable', 'keep_releases', 'dockerized', 'stack_name', 'compose_files', 'app_services',
        'migrate_service', 'delivery_strategies', 'wait_for_ci', 'health_url', 'health_command', 'health_timeout', 'auto_rollback',
        'activation', 'activation_script', 'detection', 'detected_at', 'shared_paths', 'auto_deploy', 'blue_green',
        'backup_role', 'backup_keep', 'image_templates', 'server_builds',
    ];

    public const HOOK_FIELDS = ['phase', 'name', 'command', 'run_in', 'service', 'timeout', 'continue_on_error', 'position'];

    public const ACTION_FIELDS = ['source', 'key', 'file', 'name', 'definition', 'errors', 'commit_sha', 'loaded_at'];

    public const RELEASE_FIELDS = [
        'name', 'path', 'layout', 'commit_sha', 'commit_message', 'strategy', 'size_bytes', 'ran_migrations', 'migrations',
        'backup', 'image_pins', 'adopted', 'color', 'activated_at',
    ];

    /**
     * @param  list<string>|null  $slugs  null for every active project
     * @return array<string, mixed>
     */
    public function __invoke(?array $slugs = null): array
    {
        $projects = Project::query()->active()
            ->when($slugs !== null, fn ($query) => $query->whereIn('slug', $slugs))
            ->with(['envFiles', 'hooks', 'actions', 'targets' => fn ($query) => $query->active()->with(['server', 'releases'])])
            ->orderBy('name')
            ->get();

        return [
            'format' => TransferCode::FORMAT,
            'version' => TransferCode::VERSION,
            'app_version' => Updates::current(),
            'exported_at' => now()->toIso8601String(),
            'projects' => $projects->map(fn (Project $project) => $this->project($project))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function project(Project $project): array
    {
        /** @var Collection<int, ProjectEnvFile> $envFiles */
        $envFiles = $project->envFiles;
        /** @var Collection<int, Target> $targets */
        $targets = $project->targets;

        return [
            ...self::fields($project, self::PROJECT_FIELDS),
            // Home-relative, so another user name on the other machine still finds it.
            'local_path' => $project->local_path !== null ? Format::homePath($project->local_path) : null,
            'env_files' => $envFiles->map(fn (ProjectEnvFile $file) => [
                'path' => $file->path,
                'role' => $file->role->value,
                'content' => $file->content,
            ])->all(),
            'hooks' => $project->hooks->map(fn (Model $hook) => self::fields($hook, self::HOOK_FIELDS))->all(),
            'actions' => $project->actions->map(fn (Model $action) => self::fields($action, self::ACTION_FIELDS))->all(),
            'targets' => $targets->map(fn (Target $target) => $this->target($target))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function target(Target $target): array
    {
        /** @var Collection<int, Release> $all */
        $all = $target->releases;
        // What's still on the server whole: a failed or pruned one is nothing to go back to.
        $releases = $all->filter(fn (Release $release) => $release->status === ReleaseStatus::Ready && $release->pruned_at === null);

        return [
            'server' => $target->onServer()->ssh_alias,
            'server_name' => $target->onServer()->name,
            'path' => $target->path,
            'bootstrapped_at' => $target->bootstrapped_at?->toIso8601String(),
            'last_deployed_at' => $target->last_deployed_at?->toIso8601String(),
            'current' => $releases->firstWhere('id', $target->current_release_id)?->name,
            'previous' => $releases->firstWhere('id', $target->previous_release_id)?->name,
            // Oldest first, so the other machine numbers them in the same order.
            'releases' => $releases->sortBy('id')->map(fn (Release $release) => self::fields($release, self::RELEASE_FIELDS))->values()->all(),
        ];
    }

    /**
     * The model's values as JSON keeps them: enums by value, times in ISO 8601.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function fields(Model $model, array $keys): array
    {
        $fields = [];

        foreach ($keys as $key) {
            $value = $model->getAttribute($key);
            $fields[$key] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
                default => $value,
            };
        }

        return $fields;
    }
}
