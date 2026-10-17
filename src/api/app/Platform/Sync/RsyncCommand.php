<?php

namespace App\Platform\Sync;

use App\Enums\SyncDirection;
use App\Models\FolderMapping;
use App\Platform\Ssh\SshAlias;

/**
 * The rsync command line for a folder mapping. Every value is its own
 * argument (no shell), and --protect-args keeps the remote path away from
 * the remote shell as well.
 */
final class RsyncCommand
{
    /**
     * @return list<string>
     */
    public static function for(FolderMapping $mapping, string $sshConfig, bool $dryRun, ?int $maxDelete): array
    {
        $alias = $mapping->server->ssh_alias;
        SshAlias::assertValid($alias);

        $local = rtrim($mapping->local_path, '/').'/';
        $remote = $alias.':'.rtrim($mapping->remote_path, '/').'/';
        [$source, $destination] = $mapping->direction === SyncDirection::Push ? [$local, $remote] : [$remote, $local];

        $args = [
            (string) config('deployme.sync.rsync_binary', 'rsync'),
            '--archive',
            '--compress',
            '--protect-args',
            '--no-human-readable',
            '--itemize-changes',
            '--out-format=%i %l %n%L',
            '--stats',
            '--rsh='.config('deployme.ssh.binary', 'ssh').' -F '.$sshConfig,
        ];

        if ($dryRun) {
            $args[] = '--dry-run';
        }

        if ($mapping->delete_extraneous) {
            $args[] = '--delete';

            if ($maxDelete !== null) {
                // 0 still reports extra files, without deleting any.
                $args[] = '--max-delete='.$maxDelete;
            }
        }

        foreach ($mapping->excludes ?? [] as $pattern) {
            $args[] = '--exclude='.$pattern;
        }

        return [...$args, $source, $destination];
    }
}
