<?php

namespace App\Deploy\Pipeline;

/**
 * How long one step may wait for the server. The job that runs the whole
 * deploy is killed at RunDeployment::TIMEOUT, and a step allowed longer than
 * that would take the run down with it — the queue would record "the deploy
 * worker stopped" instead of the step saying what it had been waiting for.
 * So every step timeout a project's own settings can stretch (a long
 * stop_grace_period, a slow health check, a hook) passes through here.
 * tests/Feature/QueueConfigTest.php keeps MAX under the job's budget.
 */
final class StepTimeout
{
    public const MAX = 3600;

    public const MIN = 30;

    public static function cap(int $seconds): int
    {
        return max(self::MIN, min($seconds, self::MAX));
    }
}
