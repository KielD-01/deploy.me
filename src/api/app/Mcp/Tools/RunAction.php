<?php

namespace App\Mcp\Tools;

use App\Actions\Deployments\StartAction;
use App\Deploy\DeploymentBusy;
use App\Deploy\DeploymentRefused;
use App\Mcp\Describe;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Mcp\ToolFailed;
use App\Models\User;

class RunAction extends Tool
{
    // Who the run says started it: an agent over MCP, or the Console theme's shell.
    public string $trigger = 'mcp';

    // The shell sets it when you confirmed with a passkey just now.
    public bool $identityConfirmed = false;

    public function name(): string
    {
        return 'run_action';
    }

    public function description(): string
    {
        return 'Runs one of a project’s actions on one of its servers, in the live release’s folder, with the inputs given (the others take their defaults). Only actions open to agents (list_actions says open_to_agents) can be started this way, never one that asks for a passkey. It takes the folder’s deploy lock, so it’s refused while a deploy runs there. It queues the run and answers with its number at once; follow it with get_deployment. Ask the person first: this runs commands on their server.';
    }

    public function properties(): array
    {
        return [
            'project' => ['type' => 'string', 'description' => 'The project’s slug or name.'],
            'action' => ['type' => 'string', 'description' => 'The action’s key (its file name, like reindex-search) or name.'],
            'server' => ['type' => 'string', 'description' => 'The server to run it on (slug, name or ssh alias). Needed when it may run on more than one.'],
            'inputs' => ['type' => 'object', 'description' => 'Input values by name: strings, numbers or booleans as list_actions describes them.', 'additionalProperties' => true],
        ];
    }

    public function required(): array
    {
        return ['project', 'action'];
    }

    public function deploys(): bool
    {
        return true;
    }

    public function call(array $arguments): array
    {
        $project = Find::project($this->requiredString($arguments, 'project'));
        $action = ListActions::find($project, $this->requiredString($arguments, 'action'));
        $definition = $action->definition();
        $server = $this->string($arguments, 'server');

        if ($server === null && $definition !== null && ($allowed = $definition->allowedTargets($project))->count() === 1) {
            $target = $allowed->first();
        } else {
            $target = Find::target($project, $server);
        }

        $inputs = $arguments['inputs'] ?? [];

        if (! is_array($inputs) || (array_is_list($inputs) && $inputs !== [])) {
            throw new ToolFailed('“inputs” must be an object of input names to values.');
        }

        try {
            $run = app(StartAction::class)($action, $target, $inputs, User::query()->first(), $this->trigger, identityConfirmed: $this->identityConfirmed);
        } catch (DeploymentBusy|DeploymentRefused $e) {
            throw new ToolFailed($e->getMessage());
        }

        return ['started' => true, ...Describe::deployment($run->load(['project', 'server']))];
    }
}
