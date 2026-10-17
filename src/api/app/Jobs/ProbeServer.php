<?php

namespace App\Jobs;

use App\Actions\Servers\RecordProbe;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProbeServer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Below the probes worker's --timeout=120 (supervisor/conf.d/queues.conf).
    public int $timeout = 110;

    public int $uniqueFor = 300;

    public function __construct(public int $serverId)
    {
        $this->onConnection('redis-probes')->onQueue('probes');
    }

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(RecordProbe $record): void
    {
        $server = Server::query()->active()->find($this->serverId);

        if ($server !== null) {
            $record($server, retry: true);
        }
    }
}
