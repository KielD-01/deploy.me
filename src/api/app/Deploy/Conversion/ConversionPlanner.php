<?php

namespace App\Deploy\Conversion;

use App\Deploy\Compose;
use App\Models\Project;
use App\Models\Target;
use Illuminate\Support\Str;

/**
 * Turns a read of a target's folder (targets.folder_scan) into a plan: what
 * goes into the first release, what into shared/, what stays where it is, and
 * why; plus the checks that must pass first. It only reads the scan and the
 * project's settings, never the server.
 */
final class ConversionPlanner
{
    // Installed dependencies: not in a repository, yet the app runs on them.
    private const DEPENDENCIES = ['vendor', 'node_modules'];

    /**
     * @param  array<string, mixed>  $scan
     * @param  array<string, string>  $choices  path => destination picked on the page
     * @return array<string, mixed>
     */
    public function plan(Target $target, array $scan, array $choices = []): array
    {
        $project = $target->project;
        $comparison = is_array($scan['comparison'] ?? null) && ! isset($scan['comparison']['error']) ? $scan['comparison'] : null;
        $found = collect($scan['paths'] ?? [])->keyBy('path');
        $candidates = $this->sharedCandidates($project, $scan);
        $rows = [];

        foreach ($scan['items'] ?? [] as $item) {
            $name = (string) $item['name'];
            [$to, $why, $call] = $this->suggest($project, $scan, $comparison, $item, $candidates);
            $row = [
                'path' => $name,
                'type' => (string) $item['type'],
                'bytes' => (int) $item['bytes'],
                'files' => (int) $item['files'],
                'why' => $why,
                'call' => $call,
                'suggested' => $to,
                'to' => $this->pick($choices[$name] ?? null, $to, [Conversion::RELEASE, Conversion::SHARED, Conversion::STAYS]),
                'options' => [Conversion::RELEASE, Conversion::SHARED, Conversion::STAYS],
                'nested' => false,
                'same_fs' => (bool) ($item['same_fs'] ?? true),
            ];
            $rows[] = $row;

            // Shared paths inside a folder the release takes go on to shared/.
            if ($row['type'] !== 'd' || $row['to'] !== Conversion::RELEASE) {
                continue;
            }

            foreach ($candidates as $path => $reason) {
                if (! str_starts_with($path, $name.'/') || ! $found->has($path) || $this->insideAnother($path, $candidates, $found)) {
                    continue;
                }

                $rows[] = [
                    'path' => $path,
                    'type' => (string) $found[$path]['type'],
                    'bytes' => (int) $found[$path]['bytes'],
                    'files' => null,
                    'why' => $reason,
                    'call' => false,
                    'suggested' => Conversion::SHARED,
                    'to' => $this->pick($choices[$path] ?? null, Conversion::SHARED, [Conversion::RELEASE, Conversion::SHARED]),
                    'options' => [Conversion::RELEASE, Conversion::SHARED],
                    'nested' => true,
                    'same_fs' => true,
                ];
            }
        }

        $shared = array_values(array_map(fn (array $row) => $row['path'], array_filter($rows, fn (array $row) => $row['to'] === Conversion::SHARED)));

        return [
            'rows' => $rows,
            'commit' => $this->commit($scan, $comparison),
            'closest' => $comparison !== null && ! $comparison['exact'] ? ['sha' => $comparison['commit'], 'differs' => $comparison['differs']] : null,
            'comparison_error' => $scan['comparison']['error'] ?? null,
            'checks' => $this->checks($target, $scan, $rows, $shared),
            'stack' => $this->stack($project, $scan),
            'shared' => $shared,
            'shared_paths' => $this->sharedPathsAfter($project, $shared),
            'items' => count($scan['items'] ?? []),
            'bytes' => array_sum(array_map(fn (array $item) => (int) $item['bytes'], $scan['items'] ?? [])),
            'to_release' => count(array_filter($rows, fn (array $row) => ! $row['nested'] && $row['to'] === Conversion::RELEASE)),
            'to_shared' => count($shared),
        ];
    }

    /**
     * Nothing blocks converting.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function ready(array $plan): bool
    {
        return ! collect($plan['checks'])->contains(fn (array $check) => $check['state'] === 'fail');
    }

    /**
     * The plan as picked, the way a run carries it.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function conversion(Project $project, array $plan, bool $restart): Conversion
    {
        $items = [];
        $stays = [];

        foreach ($plan['rows'] as $row) {
            if ($row['nested']) {
                if ($row['to'] === Conversion::SHARED) {
                    $items[] = ['path' => $row['path'], 'type' => $row['type'], 'to' => Conversion::SHARED];
                }
            } elseif ($row['to'] === Conversion::STAYS) {
                $stays[] = $row['path'];
            } else {
                $items[] = ['path' => $row['path'], 'type' => $row['type'], 'to' => $row['to']];
            }
        }

        return new Conversion(
            $items,
            $stays,
            $plan['commit']['sha'] ?? null,
            $plan['commit']['message'] ?? null,
            $restart && Compose::builtin($project),
            $plan['shared_paths'],
            $project->shared_paths !== null ? Project::normalizePaths($project->shared_paths) : null,
        );
    }

    /**
     * @param  list<string>  $options
     */
    private function pick(mixed $picked, string $suggested, array $options): string
    {
        return is_string($picked) && in_array($picked, $options, true) ? $picked : $suggested;
    }

    /**
     * @param  array<string, mixed>  $scan
     * @param  array<string, mixed>|null  $comparison
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $candidates
     * @return array{0: string, 1: string, 2: bool} destination, why, and whether it's your call
     */
    private function suggest(Project $project, array $scan, ?array $comparison, array $item, array $candidates): array
    {
        $name = (string) $item['name'];
        $stack = (string) $project->stack_name;
        $composeFile = $project->dockerized && in_array($name, Compose::files($project), true);

        if (isset($candidates[$name])) {
            return [Conversion::SHARED, $candidates[$name], false];
        }

        $inRepository = match (true) {
            is_array($scan['tracked'] ?? null) => in_array($name, $scan['tracked'], true),
            $comparison !== null => (bool) ($comparison['items'][$name]['in_repo'] ?? false),
            default => null,
        };

        if ($inRepository === true) {
            $why = ['In the repository'];
            $stats = $comparison['items'][$name] ?? null;

            if ($item['type'] === 'd' && (int) $item['files'] > 1) {
                $why[] = number_format((int) $item['files']).' files';
            }
            if ($composeFile) {
                $why[] = "the stack {$stack} runs from it";
            }
            if (is_array($stats) && ($differs = (int) $stats['differs'] + (int) $stats['repo_only']) > 0) {
                $why[] = $differs.' '.Str::plural('file', $differs).' differ';
            }
            if (is_array($stats) && (int) $stats['server_only'] > 0) {
                $why[] = $stats['server_only'].' only on the server';
            }

            return [Conversion::RELEASE, implode(' · ', $why), false];
        }

        if (in_array($name, self::DEPENDENCIES, true)) {
            return [Conversion::RELEASE, 'Installed dependencies: not in the repository, but the app runs on them', false];
        }

        if (preg_match('/^\.env(\..+)?$/', $name) === 1 && ! str_ends_with($name, '.example')) {
            return [Conversion::STAYS, 'Not in the repository · looks like an old copy of .env. Your call.', true];
        }

        if ($inRepository === false) {
            return [Conversion::STAYS, 'Not in the repository. Your call.', true];
        }

        if ($composeFile) {
            return [Conversion::RELEASE, "The file the stack {$stack} runs from", false];
        }

        return [Conversion::STAYS, 'deploy.me couldn’t compare it with the repository. Your call.', true];
    }

    /**
     * Paths every release should share, with why: env files the stack reads or
     * deploy.me keeps, bind mounts from inside the folder, and the project's
     * shared paths.
     *
     * @param  array<string, mixed>  $scan
     * @return array<string, string>
     */
    private function sharedCandidates(Project $project, array $scan): array
    {
        $candidates = [];

        foreach ($scan['env_files'] ?? [] as $path => $services) {
            $candidates[(string) $path] = 'Env file of '.self::sentence((array) $services);
        }

        if ($project->dockerized) {
            $candidates['.env'] ??= 'Compose reads the stack’s variables from it';
        }

        foreach ($project->envFiles()->pluck('path') as $path) {
            $candidates[ltrim((string) $path, '/')] ??= 'An env file deploy.me keeps for this project';
        }

        foreach ($scan['binds'] ?? [] as $bind) {
            if ($bind['source'] !== '.') {
                $candidates[(string) $bind['source']] ??= "Mounted into {$bind['container']} at {$bind['destination']}";
            }
        }

        foreach ($scan['bind_sources'] ?? [] as $source) {
            if ($source !== '.') {
                $candidates[(string) $source] ??= 'A bind mount in the compose file';
            }
        }

        foreach ($this->keptShared($project) as $path) {
            $candidates[$path] ??= 'Every release shares it (the project’s settings)';
        }

        return $candidates;
    }

    /**
     * A shared path inside another one that exists moves with it.
     *
     * @param  array<string, string>  $candidates
     */
    private function insideAnother(string $path, array $candidates, mixed $found): bool
    {
        foreach (array_keys($candidates) as $other) {
            if ($other !== $path && str_starts_with($path, $other.'/') && str_contains($other, '/') && $found->has($other)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shared paths from the settings worth keeping. A Docker project's image
     * holds its storage/ (and the stack's volumes its data), so the Laravel
     * default only counts for projects without Docker.
     *
     * @return list<string>
     */
    private function keptShared(Project $project): array
    {
        return $project->dockerized ? Project::normalizePaths($project->shared_paths ?? []) : $project->sharedPaths();
    }

    /**
     * @param  list<string>  $shared
     * @return list<string>
     */
    private function sharedPathsAfter(Project $project, array $shared): array
    {
        // Env files deploy.me keeps are linked anyway, as files.
        $managed = Project::normalizePaths($project->envFiles()->pluck('path')->all());

        return array_values(array_diff(Project::normalizePaths([...$this->keptShared($project), ...$shared]), $managed));
    }

    /**
     * @param  array<string, mixed>  $scan
     * @param  array<string, mixed>|null  $comparison
     * @return array<string, mixed>|null
     */
    private function commit(array $scan, ?array $comparison): ?array
    {
        if (is_array($scan['git'] ?? null)) {
            return ['sha' => $scan['git']['commit'], 'message' => null, 'how' => 'git', 'dirty' => (int) $scan['git']['dirty']];
        }

        if ($comparison !== null && $comparison['exact']) {
            return ['sha' => $comparison['commit'], 'message' => $comparison['message'] ?? null, 'date' => $comparison['date'] ?? null, 'how' => 'compared'];
        }

        if (preg_match('/^[0-9a-f]{40}$/', (string) ($scan['image_tag'] ?? '')) === 1) {
            return ['sha' => $scan['image_tag'], 'message' => null, 'how' => 'image'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>|null
     */
    private function stack(Project $project, array $scan): ?array
    {
        if (! Compose::builtin($project)) {
            return null;
        }

        $prefix = $project->stack_name.'_';

        return [
            'name' => (string) $project->stack_name,
            'containers' => $scan['containers'] ?? null,
            'volumes' => array_values(array_map(fn (string $volume) => str_starts_with($volume, $prefix) ? substr($volume, strlen($prefix)) : $volume, $scan['volumes'] ?? [])),
            'binds' => $scan['binds'] ?? [],
            'services' => $project->app_services ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $scan
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $shared
     * @return list<array<string, mixed>>
     */
    private function checks(Target $target, array $scan, array $rows, array $shared): array
    {
        $project = $target->project;
        $checks = [];
        $elsewhere = collect($rows)->first(fn (array $row) => ! $row['nested'] && $row['to'] !== Conversion::STAYS && ! $row['same_fs']);

        $checks[] = $elsewhere === null
            ? ['state' => 'ok', 'text' => 'One filesystem: every move is a rename, nothing is copied']
            : ['state' => 'fail', 'text' => "{$elsewhere['path']} is on another filesystem, so it can’t move with a rename. Mark it Stays."];

        if (! ($scan['gnu'] ?? true)) {
            $checks[] = ['state' => 'fail', 'text' => 'Converting needs GNU cp and mv on the server'];
        }

        if ($scan['current_dir'] ?? false) {
            $checks[] = ['state' => 'fail', 'text' => 'current is a folder here, not a link. Move it out of the way first.'];
        }

        // The layout's own names: the scan leaves them out as deploy.me's, so a
        // project with one of its own would lose it to the layout (and undo
        // would prune its empty folders).
        $own = array_values(array_intersect(['releases', 'shared', 'current'], (array) ($scan['tracked'] ?? [])));
        if ($own !== []) {
            $checks[] = ['state' => 'fail', 'text' => 'The repository has '.self::sentence($own).' of its own, names the releases layout needs for itself'];
        }

        // A job that runs a script from the folder breaks once the script moves.
        if (($scan['cron'] ?? []) !== []) {
            $checks[] = [
                'state' => 'warn',
                'text' => 'A scheduled job runs from this folder: after converting, point it at '.rtrim($target->path, '/').'/current',
                'lines' => array_values($scan['cron']),
            ];
        }

        if (Compose::builtin($project)) {
            $linked = $shared === [] ? '' : ' with '.self::sentence($shared).' linked from shared/';

            $checks[] = match ($scan['compose_ok'] ?? null) {
                true => ['state' => 'ok', 'text' => 'Compose resolves'.$linked],
                false => ['state' => 'warn', 'text' => 'Compose can’t read the stack’s files even now: '.($scan['compose_error'] ?? 'no reason given')],
                default => ['state' => 'warn', 'text' => 'deploy.me couldn’t ask Compose about the stack'],
            };

            if (collect($scan['binds'] ?? [])->contains(fn (array $bind) => $bind['source'] === '.')) {
                $checks[] = ['state' => 'fail', 'text' => 'A container mounts the whole folder, so moving its files would pull them out from under it'];
            }
        }

        $checks[] = $this->ciCheck($target);

        $active = $target->activeDeployment()->first();
        $checks[] = match (true) {
            $active !== null => ['state' => 'fail', 'text' => "Run {$active->number()} is going on this target"],
            ($scan['locked_by'] ?? null) !== null => ['state' => 'fail', 'text' => "Deploy #{$scan['locked_by']} holds the folder’s lock"],
            default => ['state' => 'ok', 'text' => 'No other run holds the folder’s lock'],
        };

        if (! collect($rows)->contains(fn (array $row) => ! $row['nested'] && $row['to'] === Conversion::RELEASE)) {
            $checks[] = ['state' => 'fail', 'text' => 'Nothing goes into the release'];
        }

        return $checks;
    }

    /**
     * A workflow that deploys into this folder by itself would put the old
     * layout back on its next run.
     *
     * @return array<string, mixed>
     */
    private function ciCheck(Target $target): array
    {
        $root = rtrim($target->path, '/');
        $unsure = null;

        foreach ($target->project->detected()->ciDeploys() as $deploy) {
            $path = rtrim((string) ($deploy['path'] ?? ''), '/');

            if ($path !== '' && ($path === $root || str_starts_with($path.'/', $root.'/'))) {
                return [
                    'state' => 'fail',
                    'text' => 'A GitHub workflow deploys into this folder',
                    'workflow' => (string) $deploy['workflow'],
                    'script' => $deploy['script'] ?? null,
                    'automatic' => (bool) $deploy['automatic'],
                ];
            }

            if ($path === '' && $deploy['automatic']) {
                $unsure ??= $deploy;
            }
        }

        if ($unsure !== null) {
            return ['state' => 'warn', 'text' => basename((string) $unsure['workflow']).' deploys over SSH, and deploy.me couldn’t tell where to', 'workflow' => (string) $unsure['workflow']];
        }

        return ['state' => 'ok', 'text' => 'No GitHub workflow deploys into this folder'];
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param  list<string>  $words
     */
    public static function sentence(array $words): string
    {
        $words = array_values($words);

        return match (count($words)) {
            0 => '',
            1 => $words[0],
            default => implode(', ', array_slice($words, 0, -1)).' and '.end($words),
        };
    }
}
