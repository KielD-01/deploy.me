<?php

namespace App\Mcp\Tools;

use App\Actions\Deployments\StartDeployment;
use App\Deploy\Delivery\CommitOnBranch;
use App\Deploy\DeploymentBusy;
use App\Deploy\DeploymentRefused;
use App\Enums\DeploymentKind;
use App\Mcp\Describe;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Mcp\ToolFailed;
use App\Models\User;

class Deploy extends Tool
{
    // Who the run says started it: an agent over MCP, or the Console theme's shell.
    public string $trigger = 'mcp';

    public function name(): string
    {
        return 'deploy';
    }

    public function description(): string
    {
        return 'Starts a deploy of a project to one of its servers, as the deploy dialog does: the branch’s latest commit or an earlier one on the branch, through the first delivery strategy that applies. It queues the run and answers with its number at once; follow it with get_deployment. Ask the person first: this changes what their server runs.';
    }

    public function properties(): array
    {
        return [
            'project' => ['type' => 'string', 'description' => 'The project’s slug or name.'],
            'server' => ['type' => 'string', 'description' => 'The server to deploy to (slug, name or ssh alias). Needed when the project deploys to more than one.'],
            'commit' => ['type' => 'string', 'description' => 'A commit sha (7–40 hex) on the project’s branch: its head or an earlier commit. Empty: the branch’s latest commit.'],
            'backup' => ['type' => 'boolean', 'default' => true, 'description' => 'Docker projects that migrate: pg_dump the database first (when the project can).'],
            'skip_migrations' => ['type' => 'boolean', 'default' => false, 'description' => 'Docker projects that migrate: don’t run the migrations.'],
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
        $commit = PlanDeploy::commit($this->string($arguments, 'commit'));

        // An agent ships the branch: its head, or a commit on it.
        if ($commit !== null && ($refusal = app(CommitOnBranch::class)->refusal($project, $commit, $this->trigger)) !== null) {
            throw new ToolFailed($refusal);
        }

        // What was asked for; StartDeployment fills in the defaults and
        // refuses a target that can't take a deploy.
        $options = array_filter([
            'commit' => $commit,
            'backup' => $this->bool($arguments, 'backup', true) ? null : false,
            'skip_migrations' => $this->bool($arguments, 'skip_migrations', false) ? true : null,
        ], fn ($value) => $value !== null);

        try {
            $run = app(StartDeployment::class)($target, DeploymentKind::Deploy, User::query()->first(), $options, $this->trigger);
        } catch (DeploymentBusy|DeploymentRefused $e) {
            throw new ToolFailed($e->getMessage());
        }

        return ['started' => true, ...Describe::deployment($run->load(['project', 'server']))];
    }
}
