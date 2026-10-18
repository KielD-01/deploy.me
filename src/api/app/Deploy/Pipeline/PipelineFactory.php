<?php

namespace App\Deploy\Pipeline;

use App\Deploy\Actions\ActionDefinition;
use App\Deploy\Pipeline\Steps\AcquireLease;
use App\Deploy\Pipeline\Steps\Activate;
use App\Deploy\Pipeline\Steps\BackupDatabase;
use App\Deploy\Pipeline\Steps\BackUpLayout;
use App\Deploy\Pipeline\Steps\Bootstrap;
use App\Deploy\Pipeline\Steps\BreakLock;
use App\Deploy\Pipeline\Steps\BuildImages;
use App\Deploy\Pipeline\Steps\CheckCompose;
use App\Deploy\Pipeline\Steps\CheckFolder;
use App\Deploy\Pipeline\Steps\CheckStack;
use App\Deploy\Pipeline\Steps\HealthCheck;
use App\Deploy\Pipeline\Steps\InspectExisting;
use App\Deploy\Pipeline\Steps\LinkShared;
use App\Deploy\Pipeline\Steps\LinkSharedItems;
use App\Deploy\Pipeline\Steps\MoveIntoRelease;
use App\Deploy\Pipeline\Steps\PickRelease;
use App\Deploy\Pipeline\Steps\Preflight;
use App\Deploy\Pipeline\Steps\PruneImages;
use App\Deploy\Pipeline\Steps\PruneReleases;
use App\Deploy\Pipeline\Steps\PullImages;
use App\Deploy\Pipeline\Steps\RecordState;
use App\Deploy\Pipeline\Steps\RecreateServices;
use App\Deploy\Pipeline\Steps\RegistryLogin;
use App\Deploy\Pipeline\Steps\ReleaseLease;
use App\Deploy\Pipeline\Steps\ResolveAction;
use App\Deploy\Pipeline\Steps\ResolveRelease;
use App\Deploy\Pipeline\Steps\RestartStack;
use App\Deploy\Pipeline\Steps\RestorePins;
use App\Deploy\Pipeline\Steps\RestoreRelease;
use App\Deploy\Pipeline\Steps\RollBack;
use App\Deploy\Pipeline\Steps\RollServices;
use App\Deploy\Pipeline\Steps\RunActionStep;
use App\Deploy\Pipeline\Steps\RunHooks;
use App\Deploy\Pipeline\Steps\RunMigrations;
use App\Deploy\Pipeline\Steps\SyncEnvFiles;
use App\Deploy\Pipeline\Steps\UndoConversion;
use App\Deploy\Pipeline\Steps\UploadRelease;
use App\Deploy\Pipeline\Steps\WritePins;
use App\Enums\DeploymentKind;
use App\Enums\HookPhase;
use App\Models\Deployment;

/**
 * The steps a deployment runs, by kind. Cleanup steps run however the main
 * steps ended. Steps that don't apply to a project (Docker ones for a plain
 * project, say) skip themselves. A retry of a deploy picks up the release of
 * the run it retries instead of resolving a new one.
 */
class PipelineFactory
{
    /**
     * @return array{main: list<Step>, cleanup: list<Step>}
     *
     * @throws StepFailed
     */
    public function for(Deployment $deployment): array
    {
        $target = $deployment->target()->with(['project', 'currentRelease'])->firstOrFail();
        $project = $target->project;
        $retried = $deployment->resume_from !== null
            ? $deployment->retryOf()->with(['release', 'previousRelease'])->first() ?? throw new StepFailed('The run this retries is gone.')
            : null;

        // Labels name what they'll do: the release a failed deploy switches back to.
        $switchBack = new RollBack($retried !== null ? $retried->previousRelease?->name : $target->currentRelease?->name);
        $activate = new Activate($project->versionable ? 'Switch current' : 'Run deploy script');

        return match ($deployment->kind) {
            DeploymentKind::Bootstrap => [
                'main' => [new Preflight, new AcquireLease, new Bootstrap, new SyncEnvFiles, new RecordState],
                'cleanup' => [new ReleaseLease],
            ],
            DeploymentKind::Deploy, DeploymentKind::Redeploy => [
                'main' => [
                    $retried !== null ? new RestoreRelease($retried->release?->name) : new ResolveRelease,
                    new Preflight,
                    new AcquireLease,
                    new Bootstrap,
                    new UploadRelease,
                    new LinkShared,
                    new SyncEnvFiles,
                    new WritePins,
                    new CheckStack,
                    new RunHooks(HookPhase::AfterUpload),
                    new RegistryLogin,
                    new PullImages,
                    new BuildImages,
                    new BackupDatabase,
                    new RunMigrations,
                    new RunHooks(HookPhase::Release),
                    $activate,
                    new RollServices,
                    new RecreateServices,
                    new HealthCheck,
                    new RunHooks(HookPhase::AfterActivate),
                    new RecordState,
                    new PruneReleases($project->keep_releases),
                    new PruneImages,
                ],
                'cleanup' => [$switchBack, new ReleaseLease],
            ],
            DeploymentKind::Rollback => [
                'main' => [
                    new PickRelease,
                    new Preflight,
                    new AcquireLease,
                    new RegistryLogin,
                    new RestorePins,
                    new PullImages,
                    $activate,
                    new RollServices,
                    new RecreateServices,
                    new HealthCheck,
                    new RunHooks(HookPhase::AfterActivate),
                    new RecordState,
                ],
                'cleanup' => [$switchBack, new ReleaseLease],
            ],
            DeploymentKind::Adopt => [
                'main' => [new Preflight, new AcquireLease, new InspectExisting, new RecordState],
                'cleanup' => [new ReleaseLease],
            ],
            DeploymentKind::Convert => [
                'main' => [
                    new Preflight,
                    new AcquireLease,
                    new CheckFolder,
                    new BackUpLayout,
                    new MoveIntoRelease,
                    new LinkSharedItems,
                    new CheckCompose,
                    new Activate('Switch current', switchOnly: true),
                    new RestartStack,
                    new RecordState,
                ],
                'cleanup' => [new UndoConversion, new ReleaseLease],
            ],
            DeploymentKind::UndoConvert => [
                'main' => [new Preflight, new AcquireLease, new UndoConversion, new RecordState],
                'cleanup' => [new ReleaseLease],
            ],
            // One recorded step per step of the action, inside the folder's lock.
            DeploymentKind::Action => [
                'main' => [new AcquireLease, new ResolveAction, ...$this->actionSteps($deployment)],
                'cleanup' => [new ReleaseLease],
            ],
            // Takes no lock of its own: removing the lock is the whole job.
            DeploymentKind::Unlock => [
                'main' => [new BreakLock],
                'cleanup' => [],
            ],
        };
    }

    /**
     * The action's steps as the run started with them (its definition is
     * kept on the run, so an edit since doesn't change what it runs).
     *
     * @return list<Step>
     *
     * @throws StepFailed
     */
    private function actionSteps(Deployment $deployment): array
    {
        $definition = ActionDefinition::of($deployment->options['definition'] ?? null) ?? throw new StepFailed('The run doesn’t say which action to run.');

        return array_map(fn (array $step, int $index) => new RunActionStep($index + 1, $step), $definition->steps(), array_keys($definition->steps()));
    }
}
