<?php

namespace App\Deploy;

use App\Enums\Activation;
use App\Models\Project;

/**
 * How deploy.me runs a project's Compose stack: always as its own project
 * name, from one folder (a release, or the project folder), through
 * deploy-compose.sh and deploy-compose-health.sh.
 */
final class Compose
{
    /**
     * The built-in Docker steps run for dockerized projects, unless the
     * project's own deploy script takes care of the stack.
     */
    public static function builtin(Project $project): bool
    {
        return $project->dockerized && $project->activation === Activation::Builtin;
    }

    /**
     * @param  list<string>  $services
     * @return array<string, string>
     */
    public static function vars(Project $project, string $dir, string $action, array $services = [], string $command = ''): array
    {
        return [
            'DIR' => $dir,
            'STACK' => (string) $project->stack_name,
            'FILES' => implode("\n", self::files($project)),
            'ACTION' => $action,
            'SERVICES' => implode("\n", $services),
            'COMMAND' => $command,
        ];
    }

    /**
     * @return list<string>
     */
    public static function files(Project $project): array
    {
        $files = Project::normalizePaths($project->compose_files ?? []);

        if ($files !== []) {
            return $files;
        }

        $detected = $project->detected()->primaryCompose()['file'] ?? null;

        return $detected !== null ? [$detected] : ['compose.yaml'];
    }

    /**
     * What detection read in the project's compose file: its services,
     * image pins, commands and stop grace periods.
     *
     * @return array<string, mixed>|null
     */
    public static function detected(Project $project): ?array
    {
        $file = self::files($project)[0] ?? null;
        $detection = $project->detected();

        return collect($detection->composeFiles())->firstWhere('file', $file) ?? $detection->primaryCompose();
    }

    /**
     * The networks the stack joins that Compose won't create: they have to be
     * on the server already (the shared proxy-network, as a rule).
     *
     * @return list<string>
     */
    public static function externalNetworks(Project $project): array
    {
        return array_values(array_filter(
            array_map(strval(...), (array) (self::detected($project)['external_networks'] ?? [])),
            fn (string $name) => preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) === 1,
        ));
    }

    /**
     * The variables the compose file pins, and what each one holds: a tag
     * (IMAGE_TAG in `app:${IMAGE_TAG}`) or a whole image reference
     * (API_IMAGE in `${API_IMAGE:-ghcr.io/org/api:main}`).
     *
     * @return array<string, string> variable => "tag" or "image"
     */
    public static function pinKinds(Project $project): array
    {
        $kinds = [];

        foreach (self::detected($project)['pins'] ?? [] as $pin) {
            if (is_string($pin['var'] ?? null) && $pin['var'] !== '') {
                $kinds[$pin['var']] = (string) ($pin['kind'] ?? 'tag');
            }
        }

        return $kinds;
    }

    /**
     * The services a blue/green deploy starts beside the running ones: app
     * services the edge routes to that say when they're ready. Anything else,
     * a worker or a scheduler, is recreated in place as usual.
     *
     * @return list<string>
     */
    public static function rollable(Project $project): array
    {
        $compose = self::detected($project) ?? [];
        $ready = array_intersect(
            array_map(strval(...), (array) ($compose['edge_services'] ?? [])),
            array_map(strval(...), (array) ($compose['healthchecked'] ?? [])),
        );

        return array_values(array_intersect($project->app_services ?? [], $ready));
    }
}
