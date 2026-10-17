<?php

namespace App\Support;

use App\Models\Server;
use App\Models\Target;

/**
 * What a target's folder must satisfy, when a project is assigned to a server
 * and when the folder changes: the folder rules of folder mappings, then the
 * ones a deploy needs on top (in-place deploys sync the folder, so it must be
 * the project's alone).
 */
final class TargetFolderRules
{
    // Never deploy into, or inside, these: the system's own.
    public const SYSTEM = ['/bin', '/boot', '/dev', '/etc', '/lib', '/lib32', '/lib64', '/libx32', '/proc', '/run', '/sbin', '/snap', '/sys', '/tmp', '/usr', '/var/lib', '/var/log', '/var/run', '/var/cache', '/lost+found'];

    /**
     * Why the folder can't be used, or null when it can.
     */
    public static function problem(Server $server, string $path, ?Target $except = null): ?string
    {
        if (! FolderMappingRules::isRemotePath($path)) {
            return 'Use an absolute folder path at least two folders deep, like /opt/srv/app.';
        }

        if (strlen($path) > 255) {
            return 'That path is too long.';
        }

        if ($server->isReserved($path)) {
            return "{$path} is inside the shared infrastructure’s folder.";
        }

        foreach (self::SYSTEM as $system) {
            if ($path === $system || str_starts_with($path.'/', $system.'/')) {
                return "{$path} is inside {$system}, which belongs to the system.";
            }
        }

        // A home folder holds the user's own files (~/.ssh among them).
        if ($path === '/root' || preg_match('#^/(home|Users)/[^/]+$#', $path) === 1) {
            return "{$path} is a home folder. Use a folder inside it, like {$path}/apps/".basename($path).'.';
        }

        $others = Target::query()->active()
            ->where('server_id', $server->id)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->with('project')
            ->get();

        foreach ($others as $other) {
            $theirs = rtrim($other->path, '/');

            if ($theirs === $path) {
                return "{$other->project->name} already deploys to {$path}.";
            }

            if (str_starts_with($path.'/', $theirs.'/')) {
                return "{$path} is inside {$theirs}, where {$other->project->name} deploys.";
            }

            if (str_starts_with($theirs.'/', $path.'/')) {
                return "{$other->project->name} deploys inside it, to {$theirs}.";
            }
        }

        return null;
    }

    /**
     * "/opt/srv/" and " /opt/srv" read as /opt/srv.
     */
    public static function normalize(string $path): string
    {
        return rtrim(trim($path), '/') ?: '/';
    }
}
