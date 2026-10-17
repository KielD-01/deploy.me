<?php

namespace App\Platform\Local;

/**
 * The host folders mounted into the containers at the same paths
 * (DEPLOYME_PROJECT_ROOTS). Local imports and folder pickers stay inside them.
 */
final class ProjectRoots
{
    /**
     * @param  list<string>  $roots
     */
    public function __construct(
        private readonly array $roots,
        private readonly ?string $home = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            array_values(array_filter(array_map(fn ($root) => rtrim((string) $root, '/'), config('deployme.project_roots', [])))),
            config('deployme.host_home') ?: null,
        );
    }

    /**
     * @return list<array{path: string, exists: bool}>
     */
    public function roots(): array
    {
        return array_map(fn (string $root) => ['path' => $root, 'exists' => is_dir($root)], $this->roots);
    }

    /**
     * The real path of a folder inside one of the roots, or null. `~/…` is
     * read the way the Mac would read it.
     */
    public function resolve(string $path): ?string
    {
        $path = $this->normalize($path);

        if (! str_starts_with($path, '/')) {
            return null;
        }

        $real = realpath($path);

        return $real !== false && is_dir($real) && $this->within($real) ? $real : null;
    }

    /**
     * `~/…` expanded the way the Mac would, surrounding space and a trailing slash dropped.
     */
    public function normalize(string $path): string
    {
        $path = trim($path);

        if ($this->home !== null && ($path === '~' || str_starts_with($path, '~/'))) {
            $path = rtrim($this->home, '/').substr($path, 1);
        }

        return $path === '/' ? $path : rtrim($path, '/');
    }

    /**
     * Whether a path is inside one of the roots, even one that doesn't exist
     * yet: its nearest existing folder decides.
     */
    public function allows(string $path): bool
    {
        $path = $this->normalize($path);

        if (! str_starts_with($path, '/') || in_array('..', explode('/', $path), true) || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        $existing = $path;
        while (! file_exists($existing) && $existing !== '/') {
            $existing = dirname($existing);
        }

        $real = realpath($existing);

        return $real !== false && $this->within($real);
    }

    /**
     * One of the roots itself (~/Claude), rather than a folder inside it.
     */
    public function isRoot(string $path): bool
    {
        $real = realpath($this->normalize($path));

        foreach ($this->roots as $root) {
            if ($real !== false && $real === realpath($root)) {
                return true;
            }
        }

        return false;
    }

    private function within(string $real): bool
    {
        foreach ($this->roots as $root) {
            $rootReal = realpath($root);

            if ($rootReal !== false && ($real === $rootReal || str_starts_with($real, $rootReal.'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Folders directly inside a folder, hidden ones left out.
     *
     * @return list<array{name: string, path: string, git: bool}>
     */
    public function folders(string $path): array
    {
        $real = $this->resolve($path);
        if ($real === null) {
            return [];
        }

        $folders = [];
        foreach (@scandir($real) ?: [] as $name) {
            $full = "{$real}/{$name}";

            if ($name[0] !== '.' && is_dir($full) && ! is_link($full)) {
                $folders[] = ['name' => $name, 'path' => $full, 'git' => file_exists("{$full}/.git")];
            }
        }

        return $folders;
    }
}
