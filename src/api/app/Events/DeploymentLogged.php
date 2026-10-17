<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * New log lines for the run page. When a batch is too big for one Reverb
 * message, `lines` is empty and the page fetches from..to itself.
 */
class DeploymentLogged implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<array{0: int, 1: string, 2: ?string, 3: string, 4: string}>  $lines  [seq, stream, step, logged at as a UTC instant, text]
     */
    public function __construct(
        public int $deploymentId,
        public array $lines,
        public int $from,
        public int $to,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("deployments.{$this->deploymentId}")];
    }

    public function broadcastAs(): string
    {
        return 'log';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'lines' => $this->lines];
    }
}
