<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Runs the migrations from the new release, in a one-off container of the
 * project's migrate service, before it goes live: a failure stops the deploy
 * with the old release still live.
 */
final class RunMigrations implements Step
{
    public const KEY = 'migrate';

    public const COMMAND = 'php artisan migrate --force';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Run migrations';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return self::applies($context);
    }

    public static function applies(DeployContext $context): bool
    {
        return self::availableFor($context->project)
            && ($context->memo['release'] ?? null) instanceof Release
            && ! ($context->deployment->options['skip_migrations'] ?? false);
    }

    /**
     * Whether this step migrates the project at all, whatever one deploy asks
     * for: a dockerized project with a migrate service, whose stack deploy.me
     * runs itself. Anything else migrates in a hook, or not at all.
     */
    public static function availableFor(Project $project): bool
    {
        return Compose::builtin($project) && (string) $project->migrate_service !== '';
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];
        $service = (string) $context->project->migrate_service;

        $run = $context->script('deploy-compose', Compose::vars($context->project, $release->path, 'run', [$service], self::commandFor($context->project, $service)), timeout: StepTimeout::LONG)
            ->throwIfFailed('The migrations failed, so the new release didn’t go live.');

        // Laravel lists each migration it ran with DONE.
        $count = preg_match_all('/^\s*\d{4}_\d{2}_\d{2}_\d{6}_\S+\s.*\bDONE\b/m', $run->result->stdout);
        $release->update(['ran_migrations' => $count > 0, 'migrations' => $count]);

        return $count > 0 ? $count.' '.Str::plural('migration', $count).' ran' : 'nothing to migrate';
    }

    /**
     * A service that migrates by itself (a dedicated one with its own command,
     * like `migrate --force --database=pgsql_direct`) runs that command: empty
     * means "the service's own". Any other gets `php artisan migrate --force`.
     */
    public static function commandFor(Project $project, string $service): string
    {
        return str_contains((string) (Compose::detected($project)['commands'][$service] ?? ''), 'migrate') ? '' : self::COMMAND;
    }
}
