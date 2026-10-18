<?php

namespace App\Actions\Projects;

use App\Deploy\Actions\RepoActions;
use App\Enums\CiState;
use App\Enums\ProjectSource;
use App\Jobs\PollCiStatus;
use App\Models\Credential;
use App\Models\Project;
use App\Platform\Detection\Detection;

/**
 * Creates a project from what an import found and the settings you chose.
 */
class ImportProject
{
    /**
     * @param  array{name: string, slug: string, branch: string, versionable: bool, keep_releases: int, dockerized: bool, stack_name: ?string, compose_file: ?string, strategies: list<array{key: string, enabled: bool}>}  $settings
     */
    public function __invoke(Detection $detection, ProjectSource $source, array $settings): Project
    {
        $github = $detection->github();
        $compose = collect($detection->composeFiles())->firstWhere('file', $settings['compose_file']) ?? $detection->primaryCompose();
        $dockerized = $settings['dockerized'] && $compose !== null;
        $ci = $github['ci'] ?? null;

        $project = Project::query()->create([
            'name' => $settings['name'],
            'slug' => $settings['slug'],
            'source' => $source,
            'local_path' => $source === ProjectSource::Local ? ($detection->data['path'] ?? null) : null,
            'git_remote' => $github['remote'] ?? null,
            'github_owner' => $github['owner'] ?? null,
            'github_repo' => $github['repo'] ?? null,
            'github_repo_id' => $github['id'] ?? null,
            'credential_id' => $github !== null ? Credential::forOwner($github['owner'])?->id : null,
            'branch' => $settings['branch'],
            'framework' => $detection->frameworkLabel(),
            'versionable' => $settings['versionable'],
            'keep_releases' => $settings['keep_releases'],
            'dockerized' => $dockerized,
            'stack_name' => $dockerized ? $settings['stack_name'] : null,
            'compose_files' => $dockerized ? [$compose['file']] : null,
            'app_services' => $dockerized ? $compose['app_services'] : null,
            'migrate_service' => $dockerized ? $detection->suggestedMigrateService() : null,
            'delivery_strategies' => $settings['strategies'],
            'activation_script' => $detection->suggestedActivationScript(),
            'detection' => $detection->data,
            'detected_at' => now(),
            'ci_state' => match (true) {
                $github === null => CiState::NoRemote,
                $ci !== null => CiState::from($ci['state']),
                default => null,
            },
            'ci_run' => $ci['run'] ?? null,
            'ci_checked_at' => $ci !== null ? now() : null,
        ]);

        if ($project->hasGitHub() && $ci === null) {
            PollCiStatus::dispatch($project->id);
        }

        // Its actions (.deployme/actions in the repository), at the branch head.
        app(RepoActions::class)->afterDetection($project, $detection->data);

        return $project;
    }
}
