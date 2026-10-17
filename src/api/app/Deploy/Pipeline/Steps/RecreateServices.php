<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;
use App\Models\Project;
use App\Models\Release;

/**
 * docker compose up from the release's own folder: anything missing or
 * changed starts, and the app services it left alone are recreated, so each
 * one runs the new code and restarts once.
 */
final class RecreateServices implements Step
{
    public function key(): string
    {
        return 'recreate';
    }

    public function label(): string
    {
        return 'Recreate app services';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return Compose::builtin($context->project) && ($context->memo['release'] ?? null) instanceof Release;
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];

        self::recreate($context, $release);
        $context->memo['live'] = true;

        $services = self::services($context);

        return match (true) {
            $services !== [] => implode(', ', $services),
            ($context->memo['rolled'] ?? []) !== [] => 'nothing else to recreate',
            default => 'every service',
        };
    }

    public static function recreate(DeployContext $context, Release $release): void
    {
        $context->script('deploy-compose', Compose::vars($context->project, $release->path, 'recreate', self::services($context)), timeout: self::timeout($context->project))
            ->throwIfFailed('Recreating the app services failed.');
    }

    /**
     * The app services this step still has to recreate. A blue/green deploy has
     * already started fresh containers for the ones it rolled, and recreating
     * those would restart what just came up.
     *
     * @return list<string>
     */
    private static function services(DeployContext $context): array
    {
        return array_values(array_diff($context->project->app_services ?? [], $context->memo['rolled'] ?? []));
    }

    /**
     * Compose waits out a service's stop_grace_period before it replaces it (a
     * worker finishing its job), so the step allows the longest of them, and
     * time to start everything again — up to the budget one step gets.
     */
    public static function timeout(Project $project): int
    {
        $grace = array_map(intval(...), (array) (Compose::detected($project)['stop_grace'] ?? []));

        return StepTimeout::cap(max(900, max([0, ...array_values($grace)]) + 300));
    }
}
