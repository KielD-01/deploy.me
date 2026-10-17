<?php

namespace App\Mcp\Tools;

use App\Mcp\Describe;
use App\Mcp\Tool;
use App\Models\Project;
use App\Models\Target;

class ListProjects extends Tool
{
    public function name(): string
    {
        return 'list_projects';
    }

    public function description(): string
    {
        return 'Every project with its branch, CI state and where it is deployed: per server, its health, the live release (commit) and the last run.';
    }

    public function call(array $arguments): array
    {
        $projects = Project::query()->active()
            ->with(['targets' => fn ($query) => $query->active()->orderBy('id'), 'targets.project', 'targets.server', 'targets.currentRelease', 'targets.latestDeployment'])
            ->orderBy('name')
            ->get();

        return [
            'projects' => $projects->map(fn (Project $project) => [
                'slug' => $project->slug,
                'name' => $project->name,
                'branch' => $project->branch,
                'ci' => $project->ci_state?->value,
                'auto_deploy' => (bool) $project->auto_deploy,
                'targets' => $project->targets->map(fn (Target $target) => Describe::target($target))->all(),
            ])->all(),
        ];
    }
}
