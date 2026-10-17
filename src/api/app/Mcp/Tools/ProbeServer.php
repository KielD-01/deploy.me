<?php

namespace App\Mcp\Tools;

use App\Jobs\ProbeServer as Probe;
use App\Mcp\Find;
use App\Mcp\Tool;

class ProbeServer extends Tool
{
    public function name(): string
    {
        return 'probe_server';
    }

    public function description(): string
    {
        return 'Queues a fresh probe of a server (the same read-only check the scheduler runs every few minutes). It answers at once; read the result with get_server a few seconds later.';
    }

    public function properties(): array
    {
        return [
            'server' => ['type' => 'string', 'description' => 'The server’s slug, name or ssh alias.'],
        ];
    }

    public function required(): array
    {
        return ['server'];
    }

    public function call(array $arguments): array
    {
        $server = Find::server($this->requiredString($arguments, 'server'));

        Probe::dispatch($server->id);

        return ['queued' => true, 'server' => $server->slug, 'last_probe_at' => $server->last_probe_at?->toIso8601String()];
    }
}
