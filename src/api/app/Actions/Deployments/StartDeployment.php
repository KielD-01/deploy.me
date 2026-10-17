<?php

namespace App\Actions\Deployments;

use App\Deploy\DeploymentBusy;
use App\Deploy\DeploymentRefused;
use App\Deploy\Pipeline\Steps\BackupDatabase;
use App\Deploy\Pipeline\Steps\RunMigrations;
use App\Enums\DeploymentKind;
use App\Events\DeploymentUpdated;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Target;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues a run against a target. One run per target at a time: the database
 * refuses a second active one. It's the one place that says whether a target
 * may take a run at all, and with which options, for every way a run starts:
 * the pages, the Console shell, MCP agents and CI's automatic deploys.
 */
class StartDeployment
{
    // The kinds that ship a release from the repository into the folder.
    private const SHIPS = [DeploymentKind::Deploy, DeploymentKind::Redeploy];

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $attributes  more of the run's columns: a retry's retry_of_id, resume_from and strategy
     *
     * @throws DeploymentBusy
     * @throws DeploymentRefused
     */
    public function __invoke(Target $target, DeploymentKind $kind, ?User $user = null, array $options = [], string $trigger = 'manual', array $attributes = []): Deployment
    {
        $this->check($target, $kind);
        $options = self::options($target->project, $kind, $options);

        try {
            // Its own (nested) transaction, so a refused insert doesn't poison
            // a transaction the caller has open.
            $deployment = DB::transaction(fn () => Deployment::query()->create([
                ...$attributes,
                'target_id' => $target->id,
                'project_id' => $target->project_id,
                'server_id' => $target->server_id,
                'user_id' => $user?->id,
                'kind' => $kind,
                'trigger' => $trigger,
                'options' => $options,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new DeploymentBusy('A deploy is already queued or running for this target.');
        }

        rescue(fn () => DeploymentUpdated::dispatch($deployment->id, $deployment->project_id, $deployment->status->value), report: false);
        RunDeployment::dispatch($deployment->id);

        return $deployment;
    }

    /**
     * Whether the target can take a run of this kind as it is. A folder an
     * earlier deployment still runs from is converted before anything
     * deploys into it (pre-flight refuses it on the server too).
     *
     * @throws DeploymentRefused
     */
    public function check(Target $target, DeploymentKind $kind): void
    {
        if (in_array($kind, self::SHIPS, true) && $target->needsConversion()) {
            throw DeploymentRefused::unconverted([$target]);
        }
    }

    /**
     * The run's options with the defaults filled in. A deploy of a Docker
     * project that migrates backs up first when the project can (asked not
     * to, it doesn't), and runs its migrations unless asked to skip them.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function options(Project $project, DeploymentKind $kind, array $options = []): array
    {
        if (! in_array($kind, self::SHIPS, true) || ! self::migrates($project)) {
            return $options;
        }

        return [
            ...$options,
            'backup' => (bool) ($options['backup'] ?? true) && BackupDatabase::available($project),
            'skip_migrations' => (bool) ($options['skip_migrations'] ?? false),
        ];
    }

    /**
     * A deploy runs the project's migrations: the backup and skip choices apply.
     */
    public static function migrates(Project $project): bool
    {
        return RunMigrations::availableFor($project);
    }

    /**
     * A deploy can pg_dump the database before migrating.
     */
    public static function canBackUp(Project $project): bool
    {
        return self::migrates($project) && BackupDatabase::available($project);
    }
}
