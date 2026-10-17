<?php

namespace App\Platform\Detection;

use App\Platform\GitHub\GitHubClient;

/**
 * Files in a GitHub repository at a ref, through the API: one call for the
 * tree, then the files detection reads, fetched together.
 */
final class GitHubFiles implements FileSource
{
    /** @var list<string>|null */
    private ?array $files = null;

    private bool $truncated = false;

    /** @var array<string, ?string> */
    private array $contents = [];

    public function __construct(
        private readonly GitHubClient $github,
        private readonly string $owner,
        private readonly string $repo,
        private readonly string $ref,
        private readonly int $depth = 3,
    ) {}

    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $tree = $this->github->tree($this->owner, $this->repo, $this->ref);
        $this->truncated = $tree['truncated'];

        $files = [];
        foreach ($tree['tree'] as $node) {
            if (($node['type'] ?? '') !== 'blob') {
                continue;
            }

            $segments = explode('/', (string) $node['path']);
            $folders = array_slice($segments, 0, -1);

            if (count($folders) <= $this->depth && array_intersect($folders, LocalFiles::SKIP) === []) {
                $files[] = (string) $node['path'];
            }
        }

        sort($files);

        return $this->files = $files;
    }

    /**
     * @param  list<string>  $paths
     */
    public function prefetch(array $paths): void
    {
        $missing = array_values(array_filter(
            $paths,
            fn (string $path) => ! array_key_exists($path, $this->contents) && in_array($path, $this->files(), true),
        ));
        $this->contents = [...$this->contents, ...$this->github->rawMany($this->owner, $this->repo, $missing, $this->ref)];
    }

    public function read(string $path): ?string
    {
        // Only what the listing has; no request for files that aren't there.
        if (! in_array($path, $this->files(), true)) {
            return null;
        }

        if (! array_key_exists($path, $this->contents)) {
            $this->prefetch([$path]);
        }

        return $this->contents[$path];
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }
}
