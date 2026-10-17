<?php

namespace App\Support;

/**
 * Cross-checks the supervisor worker list against config/queue.php.
 *
 * Each worker runs `queue:work <connection> ... --timeout=N`. If that
 * connection's retry_after is not above N, a second worker takes over a job
 * that is still running and a deploy runs twice.
 */
final class QueueTimeouts
{
    /**
     * @return list<array{connection: string, timeout: int}>
     */
    public static function workers(string $supervisorConf): array
    {
        preg_match_all('/queue:work (\S+).*?--timeout=(\d+)/', (string) @file_get_contents($supervisorConf), $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => ['connection' => $m[1], 'timeout' => (int) $m[2]], $matches);
    }

    /**
     * Workers whose connection would hand a running job to another worker.
     *
     * @return list<string>
     */
    public static function violations(string $supervisorConf): array
    {
        $problems = [];

        foreach (self::workers($supervisorConf) as ['connection' => $connection, 'timeout' => $timeout]) {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

            if ($retryAfter <= $timeout) {
                $problems[] = "{$connection}: retry_after {$retryAfter}s is not above --timeout={$timeout}";
            }
        }

        return $problems;
    }
}
