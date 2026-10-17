<?php

namespace App\Platform\Detection;

/**
 * Finds the applications in a project: a Laravel or Symfony app, possibly in
 * a subfolder like src/api; Node frameworks; Go, Rust, Python and Ruby.
 * Backends come first; the first one is the project's framework.
 */
final class FrameworkDetector
{
    private const LARAVEL_EXTRAS = [
        'laravel/octane' => 'Octane',
        'laravel/horizon' => 'Horizon',
        'laravel/reverb' => 'Reverb',
        'livewire/livewire' => 'Livewire',
        'inertiajs/inertia-laravel' => 'Inertia',
        'filament/filament' => 'Filament',
    ];

    private const NODE = [
        'next' => 'Next.js',
        'nuxt' => 'Nuxt',
        '@sveltejs/kit' => 'SvelteKit',
        'astro' => 'Astro',
        '@remix-run/node' => 'Remix',
        '@nestjs/core' => 'NestJS',
        'express' => 'Express',
        'fastify' => 'Fastify',
        'hono' => 'Hono',
        'vite' => 'Vite',
    ];

    private const UI = ['react' => 'React', 'vue' => 'Vue', 'svelte' => 'Svelte', 'solid-js' => 'Solid'];

    private const OTHER = ['go.mod' => 'Go', 'Cargo.toml' => 'Rust', 'pyproject.toml' => 'Python', 'requirements.txt' => 'Python', 'Gemfile' => 'Ruby'];

    /**
     * @param  list<string>  $files
     * @return array{primary: ?array{name: string, version: ?string, path: string, extras: list<string>}, apps: list<array{name: string, version: ?string, path: string, extras: list<string>}>}
     */
    public function detect(FileSource $source, array $files): array
    {
        $apps = [];
        $phpDirs = [];

        foreach (self::named($files, 'composer.json') as $file) {
            $dir = self::dir($file);
            $composer = json_decode((string) $source->read($file), true);
            $require = is_array($composer) ? array_merge((array) ($composer['require'] ?? []), (array) ($composer['require-dev'] ?? [])) : [];

            if (isset($require['laravel/framework']) && in_array(self::join($dir, 'artisan'), $files, true)) {
                $apps[] = [
                    'name' => 'Laravel',
                    'version' => $this->lockedVersion($source, $files, $dir, 'laravel/framework') ?? self::major((string) $require['laravel/framework']),
                    'path' => $dir,
                    'extras' => array_values(array_intersect_key(self::LARAVEL_EXTRAS, $require)),
                ];
                $phpDirs[] = $dir;
            } elseif (isset($require['symfony/framework-bundle'])) {
                $apps[] = [
                    'name' => 'Symfony',
                    'version' => $this->lockedVersion($source, $files, $dir, 'symfony/framework-bundle') ?? self::major((string) $require['symfony/framework-bundle']),
                    'path' => $dir,
                    'extras' => [],
                ];
                $phpDirs[] = $dir;
            }
        }

        foreach (self::named($files, 'package.json') as $file) {
            $dir = self::dir($file);

            // A PHP app's own package.json only builds its assets.
            if (in_array($dir, $phpDirs, true)) {
                continue;
            }

            $package = json_decode((string) $source->read($file), true);
            if (! is_array($package)) {
                continue;
            }

            $dependencies = array_merge((array) ($package['dependencies'] ?? []), (array) ($package['devDependencies'] ?? []));
            $framework = collect(self::NODE)->first(fn (string $name, string $dependency) => isset($dependencies[$dependency]), false);

            if ($framework !== false) {
                $dependency = array_search($framework, self::NODE, true);
                $apps[] = [
                    'name' => $framework,
                    'version' => self::major((string) $dependencies[$dependency]),
                    'path' => $dir,
                    'extras' => array_values(array_intersect_key(self::UI, $dependencies)),
                ];
            } elseif ($dir === '' && ! isset($package['workspaces'])) {
                $apps[] = ['name' => 'Node.js', 'version' => null, 'path' => '', 'extras' => []];
            }
        }

        foreach (self::OTHER as $marker => $name) {
            foreach (self::named($files, $marker, maxDepth: 1) as $file) {
                $dir = self::dir($file);
                $name = $name === 'Python' && in_array(self::join($dir, 'manage.py'), $files, true) ? 'Django' : $name;

                if (! collect($apps)->contains(fn (array $app) => $app['path'] === $dir && $app['name'] === $name)) {
                    $apps[] = ['name' => $name, 'version' => null, 'path' => $dir, 'extras' => []];
                }
            }
        }

        $rank = fn (array $app): int => match ($app['name']) {
            'Laravel', 'Symfony' => 0,
            'Next.js', 'Nuxt', 'SvelteKit', 'Remix', 'NestJS', 'Express', 'Fastify', 'Hono', 'Astro', 'Django' => 1,
            'Go', 'Rust', 'Python', 'Ruby' => 2,
            default => 3,
        };

        usort($apps, fn (array $a, array $b) => [$rank($a), substr_count($a['path'], '/'), $a['path']] <=> [$rank($b), substr_count($b['path'], '/'), $b['path']]);

        return ['primary' => $apps[0] ?? null, 'apps' => $apps];
    }

    /**
     * "13.31" from the version composer.lock pins.
     */
    private function lockedVersion(FileSource $source, array $files, string $dir, string $package): ?string
    {
        $path = self::join($dir, 'composer.lock');
        if (! in_array($path, $files, true)) {
            return null;
        }

        $lock = json_decode((string) $source->read($path), true);

        foreach (is_array($lock) ? (array) ($lock['packages'] ?? []) : [] as $locked) {
            if (($locked['name'] ?? null) === $package) {
                return preg_match('/^v?(\d+)\.(\d+)/', (string) ($locked['version'] ?? ''), $match) ? "{$match[1]}.{$match[2]}" : null;
            }
        }

        return null;
    }

    /**
     * "13" from a constraint like ^13.17.
     */
    private static function major(string $constraint): ?string
    {
        return preg_match('/(\d+)/', $constraint, $match) ? $match[1] : null;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private static function named(array $files, string $name, int $maxDepth = 3): array
    {
        return array_values(array_filter($files, fn (string $file) => basename($file) === $name && substr_count($file, '/') <= $maxDepth));
    }

    private static function dir(string $file): string
    {
        $dir = dirname($file);

        return $dir === '.' ? '' : $dir;
    }

    private static function join(string $dir, string $name): string
    {
        return $dir === '' ? $name : "{$dir}/{$name}";
    }
}
