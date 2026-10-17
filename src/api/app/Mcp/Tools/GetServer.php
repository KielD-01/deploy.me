<?php

namespace App\Mcp\Tools;

use App\Mcp\Describe;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Models\Target;

class GetServer extends Tool
{
    public function name(): string
    {
        return 'get_server';
    }

    public function description(): string
    {
        return 'One server in detail: its specs and tools, the latest probe (CPU, load, memory, disks, Compose stacks, busiest processes) and the projects deployed to it.';
    }

    public function properties(): array
    {
        return [
            'server' => ['type' => 'string', 'description' => 'The server’s slug, name or ssh alias (list_servers names them).'],
        ];
    }

    public function required(): array
    {
        return ['server'];
    }

    public function call(array $arguments): array
    {
        $server = Find::server($this->requiredString($arguments, 'server'));
        $targets = $server->targets()->active()->with(['project', 'server', 'currentRelease', 'latestDeployment'])->orderBy('id')->get();

        return [
            ...Describe::server($server),
            'host' => $server->connectionLabel(),
            'default_deploy_path' => $server->default_deploy_path,
            'probe_interval_minutes' => $server->probe_interval,
            'specs' => $server->specs,
            'capabilities' => $server->capabilities,
            'latest_probe' => Describe::snapshot($server->latestSnapshot),
            'targets' => $targets->map(fn (Target $target) => Describe::target($target))->all(),
        ];
    }
}
