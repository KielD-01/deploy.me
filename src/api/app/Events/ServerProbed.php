<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A server was probed (successfully or not). Pages listening on the servers
 * channels refresh themselves.
 */
class ServerProbed implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $serverId,
        public string $status,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('servers'), new PrivateChannel("servers.{$this->serverId}")];
    }

    public function broadcastAs(): string
    {
        return 'probed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->serverId, 'status' => $this->status];
    }
}
