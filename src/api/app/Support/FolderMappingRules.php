<?php

namespace App\Support;

use App\Enums\SyncDirection;
use App\Models\FolderMapping;
use App\Platform\Local\ProjectRoots;

/**
 * What a folder mapping must satisfy, checked when it's saved and again
 * before every run.
 */
final class FolderMappingRules
{
    public const MAX_EXCLUDES = 50;

    /**
     * Why the mapping can't run, or null when it can.
     */
    public static function problem(FolderMapping $mapping, ProjectRoots $roots): ?string
    {
        return self::check($mapping, $roots)[1] ?? null;
    }

    /**
     * The first rule the mapping breaks, as [form field, message].
     *
     * @return array{0: string, 1: string}|null
     */
    public static function check(FolderMapping $mapping, ProjectRoots $roots): ?array
    {
        $local = $roots->normalize($mapping->local_path);
        $remote = rtrim($mapping->remote_path, '/');

        if (! $roots->allows($local)) {
            return ['localPath', 'The folder on your Mac has to be inside '.collect($roots->roots())->map(fn (array $root) => Format::homePath($root['path']))->implode(', ').'.'];
        }

        if ($roots->isRoot($local)) {
            return ['localPath', 'Pick a folder inside '.Format::homePath($local).', not the whole of it.'];
        }

        if ($mapping->direction === SyncDirection::Push && ! is_dir($local)) {
            return ['localPath', Format::homePath($local).' doesn’t exist.'];
        }

        if (! self::isRemotePath($remote)) {
            return ['remotePath', 'The server folder has to be an absolute path at least two folders deep, like /opt/srv/app.'];
        }

        if ($mapping->direction === SyncDirection::Push && $mapping->server->isReserved($remote)) {
            return ['remotePath', "{$remote} belongs to the shared infrastructure; deploy.me never writes there."];
        }

        foreach ($mapping->excludes ?? [] as $pattern) {
            if (! self::isPattern((string) $pattern)) {
                return ['excludes', "“{$pattern}” can’t be used as a pattern."];
            }
        }

        return null;
    }

    public static function isRemotePath(string $path): bool
    {
        return str_starts_with($path, '/')
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1
            && ! in_array('..', explode('/', $path), true)
            && count(array_filter(explode('/', $path), fn (string $part) => $part !== '')) >= 2;
    }

    public static function isPattern(string $pattern): bool
    {
        return $pattern !== '' && strlen($pattern) <= 200 && preg_match('/[\x00-\x1F\x7F]/', $pattern) !== 1;
    }

    /**
     * One pattern per line, blank lines and duplicates dropped.
     *
     * @return list<string>
     */
    public static function parseExcludes(string $text): array
    {
        $patterns = array_map('trim', preg_split('/\R/', $text) ?: []);

        return array_slice(array_values(array_unique(array_filter($patterns, fn (string $pattern) => $pattern !== ''))), 0, self::MAX_EXCLUDES);
    }
}
