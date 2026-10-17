<?php

namespace App\Deploy\Conversion;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * What deploy-convert-scan.sh found in a target's folder, as the target keeps
 * it (targets.folder_scan): the folder's own items, the paths asked about that
 * exist, what a git checkout tracks, the stack's mounts, and the env files and
 * bind mounts its compose files name.
 */
final class FolderScan
{
    /**
     * @return array<string, mixed>
     */
    public static function parse(string $stdout, string $root): array
    {
        $scan = [
            'items' => [], 'paths' => [], 'tracked' => null, 'binds' => [], 'volumes' => [], 'cron' => [],
            'env_files' => [], 'bind_sources' => [],
            'git' => null, 'image_tag' => null, 'containers' => null, 'working_dir' => null,
            'compose_ok' => null, 'compose_error' => null, 'locked_by' => null,
            'gnu' => true, 'rsync' => false, 'current_dir' => false,
        ];
        $markers = [];
        $compose = [];
        $open = null;
        $buffer = [];

        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            if ($open !== null) {
                if ($line === '__COMPOSE_END__') {
                    $compose[$open] = implode("\n", $buffer);
                    [$open, $buffer] = [null, []];
                } else {
                    $buffer[] = $line;
                }

                continue;
            }

            if (preg_match('/^::deployme::([a-z_]+)=(.*)$/', $line, $match)) {
                $markers[$match[1]] = $match[2];

                continue;
            }

            $fields = explode("\t", $line);

            switch ($fields[0]) {
                case '__ITEM__':
                    if (count($fields) >= 7) {
                        $scan['items'][] = [
                            'name' => implode("\t", array_slice($fields, 6)),
                            'type' => $fields[1],
                            'bytes' => (int) $fields[2],
                            'files' => (int) $fields[3],
                            'mtime' => (int) $fields[4],
                            'same_fs' => $fields[5] === '1',
                        ];
                    }
                    break;
                case '__PATH__':
                    if (count($fields) >= 4) {
                        $scan['paths'][] = ['path' => $fields[3], 'type' => $fields[1], 'bytes' => (int) $fields[2]];
                    }
                    break;
                case '__TRACKED__':
                    $scan['tracked'][] = $fields[1] ?? '';
                    break;
                case '__BIND__':
                    if (count($fields) >= 4) {
                        $scan['binds'][] = ['container' => $fields[1], 'source' => $fields[2], 'destination' => $fields[3]];
                    }
                    break;
                case '__VOLUME__':
                    $scan['volumes'][] = $fields[1] ?? '';
                    break;
                case '__CRON__':
                    $scan['cron'][] = self::masked(implode("\t", array_slice($fields, 1)));
                    break;
                case '__COMPOSE__':
                    $open = $fields[1] ?? 'compose.yaml';
                    break;
            }
        }

        if (preg_match('/^[0-9a-f]{40}$/', $markers['git_commit'] ?? '') === 1) {
            $scan['git'] = ['commit' => $markers['git_commit'], 'dirty' => (int) ($markers['git_dirty'] ?? 0)];
        }

        $scan['image_tag'] = ($markers['image_tag'] ?? '') !== '' ? $markers['image_tag'] : null;
        $scan['containers'] = isset($markers['containers']) ? (int) $markers['containers'] : null;
        $scan['working_dir'] = ($markers['working_dir'] ?? '') !== '' ? $markers['working_dir'] : null;
        $scan['compose_ok'] = isset($markers['compose_ok']) ? $markers['compose_ok'] === '1' : null;
        $scan['compose_error'] = ($markers['compose_error'] ?? '') !== '' ? $markers['compose_error'] : null;
        $scan['locked_by'] = ($markers['locked'] ?? '') !== '' ? $markers['locked'] : null;
        $scan['gnu'] = ($markers['gnu'] ?? '1') === '1';
        $scan['rsync'] = ($markers['rsync'] ?? '0') === '1';
        $scan['current_dir'] = ($markers['current_dir'] ?? '0') === '1';
        $scan['volumes'] = array_values(array_unique(array_filter($scan['volumes'])));
        $scan['tracked'] = $scan['tracked'] !== null ? array_values(array_filter($scan['tracked'])) : null;

        foreach ($compose as $yaml) {
            $found = self::compose($yaml, $root);

            foreach ($found['env_files'] as $path => $services) {
                $scan['env_files'][$path] = array_values(array_unique([...($scan['env_files'][$path] ?? []), ...$services]));
            }

            $scan['bind_sources'] = array_values(array_unique([...$scan['bind_sources'], ...$found['binds']]));
        }

        return $scan;
    }

    /**
     * A scheduled job's line without anything that looks like a secret: a
     * value after password=, token= and the like, or a long random string.
     * Paths stay readable: a slash ends a random-looking run.
     */
    private static function masked(string $line): string
    {
        $line = (string) preg_replace('/((?:password|passwd|token|secret|key)\s*[=:]\s*)\S+/i', '$1…', $line);

        return (string) preg_replace('/[A-Za-z0-9_\-+]{40,}/', '…', $line);
    }

    /**
     * Records from a scan run with ONLY_PATHS=1.
     *
     * @return list<array{path: string, type: string, bytes: int}>
     */
    public static function paths(string $stdout): array
    {
        return self::parse($stdout, '/')['paths'];
    }

    /**
     * The env files a compose file gives its services, and the bind mounts it
     * takes from inside the folder, relative to the folder.
     *
     * @return array{env_files: array<string, list<string>>, binds: list<string>}
     */
    public static function compose(string $yaml, string $root): array
    {
        try {
            $doc = Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException) {
            return ['env_files' => [], 'binds' => []];
        }

        $root = rtrim($root, '/');
        $envFiles = [];
        $binds = [];

        foreach (is_array($doc['services'] ?? null) ? $doc['services'] : [] as $name => $service) {
            $service = $service instanceof TaggedValue ? $service->getValue() : $service;

            if (! is_array($service)) {
                continue;
            }

            foreach ((array) ($service['env_file'] ?? []) as $entry) {
                $path = is_array($entry) ? ($entry['path'] ?? null) : $entry;

                if (is_string($path) && ($path = self::inside($path, $root, bare: true)) !== null && $path !== '.') {
                    $envFiles[$path][] = (string) $name;
                }
            }

            foreach (is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $volume) {
                $volume = $volume instanceof TaggedValue ? $volume->getValue() : $volume;
                $source = match (true) {
                    is_string($volume) => explode(':', $volume, 2)[0],
                    is_array($volume) && ($volume['type'] ?? null) === 'bind' => (string) ($volume['source'] ?? ''),
                    default => '',
                };

                // A bare name is a named volume; a bind starts with . or /.
                if (($path = self::inside($source, $root, bare: false)) !== null) {
                    $binds[] = $path;
                }
            }
        }

        return ['env_files' => $envFiles, 'binds' => array_values(array_unique($binds))];
    }

    /**
     * A path from a compose file as a path inside the folder ("." for the
     * folder itself), or null when it points anywhere else.
     */
    private static function inside(string $path, string $root, bool $bare): ?string
    {
        $path = trim($path);

        if ($path === '.' || $path === './' || $path === $root || $path === $root.'/') {
            return '.';
        }

        $path = match (true) {
            str_starts_with($path, './') => substr($path, 2),
            str_starts_with($path, $root.'/') => substr($path, strlen($root) + 1),
            $bare && $path !== '' && preg_match('#^[/~$]#', $path) !== 1 => $path,
            default => null,
        };

        if ($path === null) {
            return null;
        }

        $path = rtrim($path, '/');
        $segments = explode('/', $path);

        return $path === '' || in_array('..', $segments, true) || in_array('.', $segments, true) ? null : $path;
    }
}
