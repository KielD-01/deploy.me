<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Progress of a background job a page is waiting on (publishing a repository
 * to GitHub), kept in the cache for an hour. The worker writes it; the page
 * polls it.
 */
final class JobStatus
{
    private const TTL = 3600;

    public static function start(string $id, string $step): void
    {
        Cache::put(self::key($id), ['state' => 'running', 'step' => $step, 'message' => null, 'result' => null], self::TTL);
    }

    public static function step(string $id, string $step): void
    {
        self::merge($id, ['step' => $step]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function finish(string $id, array $result = []): void
    {
        self::merge($id, ['state' => 'done', 'result' => $result]);
    }

    public static function fail(string $id, string $message): void
    {
        self::merge($id, ['state' => 'failed', 'message' => $message]);
    }

    /**
     * @return array{state: string, step: ?string, message: ?string, result: ?array}|null
     */
    public static function get(string $id): ?array
    {
        return Cache::get(self::key($id));
    }

    private static function merge(string $id, array $values): void
    {
        Cache::put(self::key($id), [...(self::get($id) ?? []), ...$values], self::TTL);
    }

    private static function key(string $id): string
    {
        return "deployme:job:{$id}";
    }
}
