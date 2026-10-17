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
    // What the steps allow their own scripts, by the kind of work.

    // A lock, a state file, forgetting a registry login.
    public const QUICK = 30;

    // Writing a file, switching current, a registry login.
    public const SHORT = 60;

    // Compose reading a stack, linking shared files, a conversion's checks.
    public const CHECK = 120;

    // Linking what a conversion moved.
    public const MEDIUM = 300;

    // Removing old releases or images.
    public const PRUNE = 600;

    // Moving a release's files into place on the server.
    public const TRANSFER = 900;

    // Pulling images, migrating, a pg_dump, the project's deploy script.
    public const LONG = 1800;

    // Building images; and the most any step may take.
    public const MAX = 3600;

    public const MIN = 30;

    public static function cap(int $seconds): int
    {
        return max(self::MIN, min($seconds, self::MAX));
    }
}
