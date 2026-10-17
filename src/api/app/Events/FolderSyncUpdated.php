<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A folder sync started or finished: the server's Folders tab refreshes.
 */
class FolderSyncUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $serverId,
        public int $mappingId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("servers.{$this->serverId}")];
    }

    public function broadcastAs(): string
    {
        return 'sync';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['mapping' => $this->mappingId];
    }
}
