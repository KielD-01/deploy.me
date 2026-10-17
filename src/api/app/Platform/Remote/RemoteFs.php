<?php

namespace App\Platform\Remote;

use App\Platform\Ssh\Exceptions\RemoteCommandFailed;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use InvalidArgumentException;

final class RemoteFs
{
    public function __construct(private readonly SshClient $ssh) {}

    public function list(string $alias, string $path, int $limit = 1000): DirectoryListing
    {
        $result = $this->ssh->run($alias, RemoteScript::file('ls', ['DIR' => self::assertPath($path), 'LIMIT' => $limit]), timeout: 20);

        if ($result->exitCode === 3) {
            throw new RemoteCommandFailed("Can’t open {$path}: it doesn’t exist or you don’t have access.");
        }

        return DirectoryListing::parse($result->throw()->stdout, $limit);
    }

    public function mkdir(string $alias, string $path): void
    {
        $this->ssh->run($alias, RemoteScript::inline('mkdir', 'mkdir -p -- "$DIR"', ['DIR' => self::assertPath($path)]), timeout: 20)->throw();
    }

    /**
     * The folder itself when it exists, otherwise the nearest one above it that does.
     */
    public function nearestFolder(string $alias, string $path): string
    {
        $script = 'd="$DIR"; while [ ! -d "$d" ] && [ "$d" != / ]; do d=$(dirname -- "$d"); done; printf "%s" "$d"';

        return trim($this->ssh->run($alias, RemoteScript::inline('nearest-dir', $script, ['DIR' => self::assertPath($path)]), timeout: 20)->throw()->stdout) ?: '/';
    }

    public function home(string $alias): string
    {
        return trim($this->ssh->run($alias, RemoteScript::inline('home', 'printf "%s" "$HOME"'), timeout: 20)->throw()->stdout) ?: '/';
    }

    public function isDirectory(string $alias, string $path): bool
    {
        return $this->ssh->run($alias, RemoteScript::inline('is-dir', '[ -d "$DIR" ]', ['DIR' => self::assertPath($path)]), timeout: 20)->successful();
    }

    public static function assertPath(string $path): string
    {
        if (! str_starts_with($path, '/') || preg_match('/[\x00\n\r]/', $path)) {
            throw new InvalidArgumentException('Use an absolute path without line breaks.');
        }

        return $path;
    }
}
