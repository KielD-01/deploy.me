<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A health check came back different from the last one: the project's
 * targets and the dashboard refresh.
 */
class TargetHealthChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $targetId,
        public int $projectId,
        public string $health,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('projects'), new PrivateChannel("projects.{$this->projectId}")];
    }

    public function broadcastAs(): string
    {
        return 'health.updated';
    }
}
