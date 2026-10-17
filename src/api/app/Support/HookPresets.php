<?php

namespace App\Support;

use App\Deploy\Pipeline\Steps\RunMigrations;
use App\Enums\HookPhase;
use App\Enums\HookRunIn;
use App\Models\Project;

/**
 * Common hook sets, adapted to the project: Docker projects run them in a
 * Compose service, others on the server in the release folder, and a project
 * whose migrations deploy.me runs itself doesn't get a hook for them.
 */
final class HookPresets
{
    public const LABELS = ['laravel' => 'Laravel', 'horizon' => 'Laravel + Horizon', 'octane' => 'Octane'];

    /**
     * @return list<array{phase: HookPhase, name: string, command: string, run_in: HookRunIn, service: ?string, timeout: int, continue_on_error: bool}>
     */
    public static function for(string $preset, Project $project): array
    {
        $release = fn (string $name, string $command, int $timeout) => [HookPhase::Release, $name, $command, $timeout, false];
        $after = fn (string $name, string $command) => [HookPhase::AfterActivate, $name, $command, 60, true];

        $hooks = [
            // A dockerized project migrates in a step of its own, in a one-off
            // container of its migrate service: a hook here would run the
            // migrations a second time.
            ...(RunMigrations::availableFor($project) ? [] : [$release('Migrate database', 'php artisan migrate --force', 600)]),
            $release('Cache config and routes', 'php artisan optimize', 120),
            ...match ($preset) {
                'horizon' => [$after('Restart Horizon', 'php artisan horizon:terminate')],
                'octane' => [$after('Restart queue workers', 'php artisan queue:restart'), $after('Reload Octane', 'php artisan octane:reload')],
                default => [$after('Restart queue workers', 'php artisan queue:restart')],
            },
        ];

        $service = $project->dockerized ? ($project->migrate_service ?? $project->app_services[0] ?? null) : null;

        return array_map(fn (array $hook) => [
            'phase' => $hook[0],
            'name' => $hook[1],
            'command' => $hook[2],
            // One-off containers before the switch; the running app after it.
            'run_in' => $service === null ? HookRunIn::Host : ($hook[0] === HookPhase::Release ? HookRunIn::ServiceRun : HookRunIn::ServiceExec),
            'service' => $service,
            'timeout' => $hook[3],
            'continue_on_error' => $hook[4],
        ], $hooks);
    }
}
