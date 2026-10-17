<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A read of a target's folder finished: its conversion plan and the
 * project's targets refresh.
 */
class TargetFolderRead implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $targetId,
        public int $projectId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("projects.{$this->projectId}")];
    }

    public function broadcastAs(): string
    {
        return 'folder.read';
    }
}
