<?php

namespace App\Mcp\Tools;

use App\Actions\Deployments\StartDeployment;
use App\Deploy\Compose;
use App\Deploy\DeploymentBusy;
use App\Deploy\Pipeline\Steps\BackupDatabase;
use App\Enums\DeploymentKind;
use App\Mcp\Describe;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Mcp\ToolFailed;
use App\Models\User;

class Deploy extends Tool
{
    public function name(): string
    {
        return 'deploy';
    }

    public function description(): string
    {
        return 'Starts a deploy of a project to one of its servers, as the deploy dialog does: the branch’s latest commit or the one named, through the first delivery strategy that applies. It queues the run and answers with its number at once; follow it with get_deployment. Ask the person first: this changes what their server runs.';
    }

    public function properties(): array
    {
        return [
            'project' => ['type' => 'string', 'description' => 'The project’s slug or name.'],
            'server' => ['type' => 'string', 'description' => 'The server to deploy to (slug, name or ssh alias). Needed when the project deploys to more than one.'],
            'commit' => ['type' => 'string', 'description' => 'A commit sha (7–40 hex). Empty: the branch’s latest commit.'],
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

        if ($target->needsConversion()) {
            throw new ToolFailed("{$target->server->name} still runs {$project->name} from an earlier deployment. Convert that folder first: it’s on the project’s Targets tab.");
        }

        $options = $commit !== null ? ['commit' => $commit] : [];

        if (Compose::builtin($project) && (string) $project->migrate_service !== '') {
            $options['backup'] = $this->bool($arguments, 'backup', true) && BackupDatabase::available($project);
            $options['skip_migrations'] = $this->bool($arguments, 'skip_migrations', false);
        }

        try {
            $run = app(StartDeployment::class)($target, DeploymentKind::Deploy, User::query()->first(), $options, 'mcp');
        } catch (DeploymentBusy $e) {
            throw new ToolFailed($e->getMessage());
        }

        return ['started' => true, ...Describe::deployment($run->load(['project', 'server']))];
    }
}
