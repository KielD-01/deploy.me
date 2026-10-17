<?php

namespace App\Mcp\Tools;

use App\Enums\DeploymentStatus;
use App\Mcp\Describe;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Mcp\ToolFailed;
use App\Models\Deployment;

class ListDeployments extends Tool
{
    public function name(): string
    {
        return 'list_deployments';
    }

    public function description(): string
    {
        return 'Recent runs (deploys, rollbacks, prepares…), newest first, optionally only one project’s, one server’s or one status.';
    }

    public function properties(): array
    {
        return [
            'project' => ['type' => 'string', 'description' => 'Only this project’s runs (slug or name).'],
            'server' => ['type' => 'string', 'description' => 'Only runs on this server (slug, name or ssh alias).'],
            'status' => ['type' => 'string', 'enum' => array_column(DeploymentStatus::cases(), 'value'), 'description' => 'Only runs in this state.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ];
    }

    public function call(array $arguments): array
    {
        $project = ($name = $this->string($arguments, 'project')) !== null ? Find::project($name) : null;
        $server = ($name = $this->string($arguments, 'server')) !== null ? Find::server($name) : null;
        $status = $this->string($arguments, 'status');

        if ($status !== null && DeploymentStatus::tryFrom($status) === null) {
            throw new ToolFailed("“{$status}” isn’t a run state. One of: ".implode(', ', array_column(DeploymentStatus::cases(), 'value')).'.');
        }

        $runs = Deployment::query()
            ->with(['project', 'server'])
            ->when($project, fn ($query) => $query->where('project_id', $project->id))
            ->when($server, fn ($query) => $query->where('server_id', $server->id))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit($this->int($arguments, 'limit', 20, max: 100))
            ->get();

        return ['deployments' => $runs->map(fn (Deployment $run) => Describe::deployment($run))->all()];
    }
}
