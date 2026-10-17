<?php

namespace App\Mcp\Tools;

use App\Mcp\Describe;
use App\Mcp\Tool;
use App\Models\Server;

class ListServers extends Tool
{
    public function name(): string
    {
        return 'list_servers';
    }

    public function description(): string
    {
        return 'The servers deploy.me knows (from ~/.ssh/config): status, environment, when each last answered, and its latest CPU, memory and disk readings.';
    }

    public function call(array $arguments): array
    {
        $servers = Server::query()->active()->with('latestSnapshot')->orderBy('name')->get();

        return [
            'servers' => $servers->map(fn (Server $server) => [
                ...Describe::server($server),
                'cpu_percent' => $server->latestSnapshot?->cpu_usage,
                'memory_used_percent' => $server->latestSnapshot?->memoryUsedPercent(),
                'probed_at' => Describe::time($server->latestSnapshot?->captured_at),
            ])->all(),
        ];
    }
}
