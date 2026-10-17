<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A deployment or one of its steps changed: the run page, the deployments
 * list and the project's targets refresh.
 */
class DeploymentUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $deploymentId,
        public int $projectId,
        public string $status,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('deployments'),
            new PrivateChannel("deployments.{$this->deploymentId}"),
            new PrivateChannel("projects.{$this->projectId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->deploymentId, 'status' => $this->status];
    }
}
