<?php

namespace App\Jobs;

use App\Actions\Targets\CheckTargetHealth;
use App\Models\Target;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One target's health check, on the probes worker.
 */
class CheckHealth implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Below the probes worker's --timeout=120 (supervisor/conf.d/queues.conf).
    public int $timeout = 110;

    public int $uniqueFor = 120;

    public function __construct(public int $targetId)
    {
        $this->onConnection('redis-probes')->onQueue('probes');
    }

    public function uniqueId(): string
    {
        return (string) $this->targetId;
    }

    public function handle(CheckTargetHealth $check): void
    {
        $target = Target::query()->active()->find($this->targetId);

        // A run changing things has its own health check.
        if ($target === null || $target->activeDeployment()->exists()) {
            return;
        }

        $check($target);
    }
}
