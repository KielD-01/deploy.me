<?php

namespace App\Deploy\Logging;

use App\Models\Deployment;
use Illuminate\Support\Facades\File;

/**
 * A finished run's log, gzipped in storage/app/deploy-logs once its lines
 * leave the database (after Settings → Schedules' retention).
 */
final class LogArchive
{
    private const PAGE = 5000;

    public static function path(int $deploymentId): string
    {
        return storage_path("app/deploy-logs/{$deploymentId}.log.gz");
    }

    public static function exists(int $deploymentId): bool
    {
        return is_file(self::path($deploymentId));
    }

    /**
     * Writes the run's log lines into its archive; false when there were none.
     */
    public static function write(Deployment $deployment): bool
    {
        $path = self::path($deployment->id);
        File::ensureDirectoryExists(dirname($path));

        $gz = gzopen("{$path}.tmp", 'wb6');
        $seq = 0;
        $written = 0;

        do {
            $lines = $deployment->logLines($seq, self::PAGE);

            foreach ($lines as $line) {
                gzwrite($gz, self::format($line)."\n");
                $seq = (int) $line->seq;
                $written++;
            }
        } while ($lines->count() === self::PAGE);

        gzclose($gz);

        if ($written === 0) {
            @unlink("{$path}.tmp");

            return false;
        }

        return rename("{$path}.tmp", $path);
    }

    /**
     * One line as the downloaded log shows it.
     */
    public static function format(object $line): string
    {
        return substr((string) $line->logged_at, 0, 19).' '.($line->step_key !== null ? "[{$line->step_key}] " : '').$line->line;
    }

    /**
     * Echoes the archive, uncompressed.
     */
    public static function stream(int $deploymentId): void
    {
        $gz = gzopen(self::path($deploymentId), 'rb');

        while (! gzeof($gz)) {
            echo gzread($gz, 65536);
        }

        gzclose($gz);
    }
}
