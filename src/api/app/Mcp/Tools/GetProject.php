<?php

namespace App\Mcp\Tools;

use App\Mcp\Describe;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Models\Release;
use App\Models\Target;

class GetProject extends Tool
{
    public function name(): string
    {
        return 'get_project';
    }

    public function description(): string
    {
        return 'One project in detail: its deploy settings, delivery strategies in priority order, and per server the live release and the recent releases a rollback could return to.';
    }

    public function properties(): array
    {
        return [
            'project' => ['type' => 'string', 'description' => 'The project’s slug or name (list_projects names them).'],
        ];
    }

    public function required(): array
    {
        return ['project'];
    }

    public function call(array $arguments): array
    {
        $project = Find::project($this->requiredString($arguments, 'project'));
        $targets = $project->targets()->active()->with(['project', 'server', 'currentRelease', 'latestDeployment'])->orderBy('id')->get();

        return [
            ...Describe::project($project),
            'keep_releases' => $project->keep_releases,
            'targets' => $targets->map(fn (Target $target) => [
                ...Describe::target($target),
                'releases' => $target->releases()->latest('id')->limit(10)->get()
                    ->map(fn (Release $release) => [...Describe::release($release, $project), 'live' => $release->id === $target->current_release_id])
                    ->all(),
            ])->all(),
        ];
    }
}
