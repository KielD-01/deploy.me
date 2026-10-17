<?php

namespace App\Deploy;

/**
 * Where everything lives on the server for one target:
 *
 *   {root}/.deploy/         lock/, state.json, runs/, incoming/  (deploy.me's own)
 *   versionable:            releases/<YmdHis>/, shared/, current → releases/<name>
 *   in place:               {root} is the working tree itself
 */
final class RemoteLayout
{
    public function __construct(
        public readonly string $root,
        public readonly bool $versionable,
    ) {}

    public function deployDir(): string
    {
        return "{$this->root}/.deploy";
    }

    public function lock(): string
    {
        return "{$this->root}/.deploy/lock";
    }

    public function stateFile(): string
    {
        return "{$this->root}/.deploy/state.json";
    }

    /**
     * Where a running deploy script notes its process group (<run>.pgid),
     * so a cancel can stop what it started.
     */
    public function runs(): string
    {
        return "{$this->root}/.deploy/runs";
    }

    /**
     * Where an in-place release is staged before it goes into place.
     */
    public function incoming(string $name): string
    {
        return "{$this->root}/.deploy/incoming/{$name}";
    }

    public function releases(): string
    {
        return "{$this->root}/releases";
    }

    public function shared(): string
    {
        return "{$this->root}/shared";
    }

    public function current(): string
    {
        return "{$this->root}/current";
    }

    public function release(string $name): string
    {
        return "{$this->root}/releases/{$name}";
    }

    /**
     * Where an env file lives on the server: shared/ when there are releases
     * (each release links to it), the project folder otherwise.
     */
    public function envFile(string $relative): string
    {
        return ($this->versionable ? $this->shared() : $this->root).'/'.ltrim($relative, '/');
    }

    /**
     * The folder tree the assign form shows before anything exists.
     *
     * @param  list<string>  $sharedFiles
     * @return list<array{name: string, note: string}>
     */
    public function preview(int $keep, array $sharedFiles): array
    {
        if (! $this->versionable) {
            return [
                ['name' => '(the project)', 'note' => 'deploys replace the files in place'],
                ['name' => '.deploy/', 'note' => 'deploy.me’s lock and state'],
            ];
        }

        return [
            ['name' => 'releases/', 'note' => $keep.' kept'],
            ['name' => 'shared/', 'note' => $sharedFiles === [] ? 'env files and storage' : implode(', ', $sharedFiles)],
            ['name' => 'current → releases/…', 'note' => 'the live release'],
        ];
    }
}
