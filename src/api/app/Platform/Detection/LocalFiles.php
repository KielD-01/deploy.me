<?php

namespace App\Platform\Detection;

/**
 * Files in a local folder. Symlinks are never followed, so a scan can't
 * wander outside the project.
 */
final class LocalFiles implements FileSource
{
    // Dependency, build, editor and agent folders: large, or copies of the
    // project (.claude/worktrees), and never what we look for.
    public const SKIP = [
        '.git', 'node_modules', 'vendor', 'storage', '.idea', '.vscode', '.cache', 'dist', 'build', '.next', '.nuxt',
        '.output', 'coverage', '.venv', 'venv', '__pycache__', 'target', '.turbo', '.terraform', 'bower_components',
        '.pnpm-store', '.yarn', '.gradle', 'Pods', '.claude', '.cursor', '.superpowers', '.worktrees',
    ];

    private const MAX_FILES = 20000;

    private const MAX_BYTES = 1_048_576;

    /** @var list<string>|null */
    private ?array $files = null;

    private bool $truncated = false;

    public function __construct(
        private readonly string $root,
        private readonly int $depth = 3,
    ) {}

    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $files = [];
        $queue = [['', 0]];

        while ($queue !== []) {
            [$dir, $level] = array_shift($queue);

            foreach (@scandir($this->root.($dir === '' ? '' : "/{$dir}")) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $relative = $dir === '' ? $name : "{$dir}/{$name}";
                $full = "{$this->root}/{$relative}";

                if (is_link($full)) {
                    continue;
                }

                if (is_dir($full)) {
                    // A folder with its own .git is another checkout (a nested
                    // repository or a worktree), not part of this project.
                    if ($level < $this->depth && ! in_array($name, self::SKIP, true) && ! file_exists("{$full}/.git")) {
                        $queue[] = [$relative, $level + 1];
                    }

                    continue;
                }

                $files[] = $relative;

                if (count($files) >= self::MAX_FILES) {
                    $this->truncated = true;
                    break 2;
                }
            }
        }

        sort($files);

        return $this->files = $files;
    }

    public function read(string $path): ?string
    {
        if ($path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            return null;
        }

        $full = "{$this->root}/{$path}";

        if (! is_file($full) || is_link($full) || filesize($full) > self::MAX_BYTES) {
            return null;
        }

        $contents = @file_get_contents($full);

        return $contents === false ? null : $contents;
    }

    public function prefetch(array $paths): void
    {
        // Local reads are cheap; nothing to batch.
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }
}
