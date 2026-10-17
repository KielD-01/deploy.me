<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A project's CI state changed: the projects list, the dashboard and the
 * project page refresh.
 */
class ProjectCiUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $projectId,
        public ?string $state,
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
        return 'ci.updated';
    }
}
