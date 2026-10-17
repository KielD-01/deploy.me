<?php

namespace App\Mcp\Tools;

use App\Actions\Deployments\StartDeployment;
use App\Deploy\DeploymentBusy;
use App\Enums\DeploymentKind;
use App\Mcp\Describe;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Mcp\ToolFailed;
use App\Models\User;

class Rollback extends Tool
{
    // Who the run says started it: an agent over MCP, or the Console theme's shell.
    public string $trigger = 'mcp';

    public function name(): string
    {
        return 'rollback';
    }

    public function description(): string
    {
        return 'Rolls a project on a server back to an earlier release (get_project lists them, with can_roll_back_to). Without a release, the one before the live one. Queues the run and answers with its number. Ask the person first.';
    }

    public function properties(): array
    {
        return [
            'project' => ['type' => 'string', 'description' => 'The project’s slug or name.'],
            'server' => ['type' => 'string', 'description' => 'The server (slug, name or ssh alias). Needed when the project deploys to more than one.'],
            'release' => ['type' => 'string', 'description' => 'The release’s name, like 20260911-120000. Empty: the previous release.'],
        ];
    }

    public function required(): array
    {
        return ['project'];
    }

    public function deploys(): bool
    {
        return true;
    }

    public function call(array $arguments): array
    {
        $project = Find::project($this->requiredString($arguments, 'project'));
        $target = Find::target($project, $this->string($arguments, 'server'));
        $name = $this->string($arguments, 'release');

        $release = $name !== null
            ? $target->releases()->where('name', $name)->first() ?? throw new ToolFailed("{$project->name} has no release {$name} on {$target->server->name}.")
            : $target->previousRelease ?? throw new ToolFailed("{$project->name} on {$target->server->name} has no previous release to go back to. Name one.");

        if ($release->id === $target->current_release_id) {
            throw new ToolFailed("Release {$release->name} is already live on {$target->server->name}.");
        }

        if (! $release->canRollBackTo($project)) {
            throw new ToolFailed($project->versionable
                ? "Release {$release->name} isn’t on the server any more."
                : "Release {$release->name} didn’t record which images it ran, so there’s nothing to put back.");
        }

        try {
            $run = app(StartDeployment::class)($target, DeploymentKind::Rollback, User::query()->first(), ['release_id' => $release->id], $this->trigger);
        } catch (DeploymentBusy $e) {
            throw new ToolFailed($e->getMessage());
        }

        return ['started' => true, 'release' => $release->name, ...Describe::deployment($run->load(['project', 'server']))];
    }
}
