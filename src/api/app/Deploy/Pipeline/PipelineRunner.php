<?php

namespace App\Deploy\Pipeline;

use App\Deploy\Conversion\Conversion;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Steps\Activate;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseStatus;
use App\Enums\StepStatus;
use App\Enums\TargetHealth;
use App\Events\DeploymentUpdated;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Release;
use App\Platform\Ssh\Exceptions\SshException;
use App\Platform\Ssh\SshClient;
use App\Support\Alert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a deployment's steps in order, recording each one (status, timing,
 * detail) and logging as it goes. The first failure stops the main steps;
 * cleanup steps run regardless, and switch back to the previous release when
 * the failure (or a cancel) came after the new one went live. A run whose
 * strategy waits for CI pauses and is picked up again later. Cancelling is
 * checked between steps and while scripts run, never during cleanup.
 */
class PipelineRunner
{
    // A run waiting for CI checks again this often, for up to an hour.
    public const RECHECK_EVERY = 20;

    public const MAX_WAIT_MINUTES = 60;

    public function __construct(
        private readonly PipelineFactory $factory,
        private readonly SshClient $ssh,
    ) {}

    public function run(Deployment $deployment): void
    {
        try {
            $pipeline = $this->factory->for($deployment);
        } catch (StepFailed $e) {
            $this->finish($deployment, DeploymentStatus::Failed, $e->getMessage());

            return;
        }

        $context = DeployContext::for($deployment, $this->ssh);

        try {
            $this->runPipeline($context, $pipeline);
        } finally {
            $context->finish();
        }
    }

    /**
     * @param  array{main: list<Step>, cleanup: list<Step>}  $pipeline
     */
    private function runPipeline(DeployContext $context, array $pipeline): void
    {
        $deployment = $context->deployment;
        $firstAttempt = $deployment->started_at === null;
        $this->createSteps($deployment, [...$pipeline['main'], ...$pipeline['cleanup']]);

        // A run that waits for CI stays "waiting" until it can go ahead.
        $deployment->forceFill(array_filter([
            'status' => $deployment->status === DeploymentStatus::WaitingCi ? null : DeploymentStatus::Running,
            'started_at' => $deployment->started_at ?? now(),
            'heartbeat_at' => now(),
        ], fn ($value) => $value !== null))->save();
        $this->announce($deployment);

        if ($firstAttempt) {
            $context->log->note("{$deployment->kind->label()} {$context->project->name} on {$context->server->name}, in {$context->layout->root}");
        }

        // A retry starts at the step the run it retries stopped at. Before it,
        // only what every run needs runs again (the AlwaysRuns steps).
        $resuming = $deployment->resume_from !== null;
        $doneThere = $resuming ? DeploymentStep::query()->where('deployment_id', $deployment->retry_of_id)->get()->keyBy('key') : collect();

        if ($resuming && $firstAttempt) {
            $from = collect($pipeline['main'])->first(fn (Step $step) => $step->key() === $deployment->resume_from)?->label() ?? $deployment->resume_from;
            $context->log->note("Retrying #{$deployment->retry_of_id} from “{$from}”: what came before it was done there.");
        }

        $status = DeploymentStatus::Succeeded;
        $outcome = null;

        try {
            foreach ($pipeline['main'] as $step) {
                $this->checkCancelled($context, $step);

                // A release that isn't live any more is switched to again,
                // and what comes after the switch runs again with it.
                if ($resuming && ($step->key() === $deployment->resume_from || ($step instanceof Activate && ! ($context->memo['live'] ?? false)))) {
                    $resuming = false;
                }

                if ($resuming && ! $step instanceof AlwaysRuns) {
                    $this->markDone($context, $step, $doneThere->get($step->key()));

                    continue;
                }

                $this->runStep($context, $step);
            }

            // Every main step ran: nothing for a cleanup step to take back.
            $context->memo['completed'] = true;
        } catch (WaitingForCi $e) {
            $this->wait($context, $e);

            return;
        } catch (DeploymentCancelled) {
            $status = DeploymentStatus::Cancelled;
            $outcome = 'Cancelled before it finished.';
        } catch (StepFailed $e) {
            $status = DeploymentStatus::Failed;
            $outcome = $e->getMessage();
        } catch (SshException $e) {
            $status = DeploymentStatus::Failed;
            $outcome = $e->userMessage();
        } catch (Throwable $e) {
            report($e);
            $status = DeploymentStatus::Failed;
            $outcome = 'deploy.me ran into an error: '.$e->getMessage();
        }

        // What the cleanup steps go by: switching back follows a failure or a cancel.
        $context->memo['failed'] = $status === DeploymentStatus::Failed;
        $context->memo['cancelled'] = $status === DeploymentStatus::Cancelled;

        // A cancel doesn't reach the cleanup: it gives the lock back and puts
        // the live release back, and stopping that halfway is the damage.
        $context->shield();

        foreach ($pipeline['cleanup'] as $step) {
            try {
                $this->runStep($context, $step);
            } catch (Throwable $e) {
                $context->log->note("! {$step->label()}: ".($e instanceof SshException ? $e->userMessage() : $e->getMessage()));
            }
        }

        // Steps the failure or cancel never reached.
        $deployment->steps()->where('status', StepStatus::Pending)->update(['status' => StepStatus::Skipped]);

        $switchedBack = $context->memo['rolled_back'] ?? null;
        if ($switchedBack instanceof Release) {
            // A cancelled run stays cancelled: switching back is part of stopping it.
            $status = $status === DeploymentStatus::Cancelled ? $status : DeploymentStatus::RolledBack;
            $outcome = rtrim((string) $outcome, '. ').". Switched back to release {$switchedBack->name}, which is live again.";
        } elseif ($status === DeploymentStatus::Cancelled && ($context->memo['live'] ?? false)
            && in_array($deployment->kind, [DeploymentKind::Deploy, DeploymentKind::Redeploy, DeploymentKind::Rollback], true)) {
            $outcome = rtrim((string) $outcome, '. ').'. It had gone live already, and there’s no earlier release on the server to switch back to.';
        }

        // A conversion that stopped halfway put every item back.
        if (($context->memo['undone'] ?? false) && $context->deployment->kind === DeploymentKind::Convert) {
            $outcome = rtrim((string) $outcome, '. ').'. Every item is back where it was.';
        }

        $this->settle($context, $status);

        if ($status === DeploymentStatus::Succeeded) {
            $outcome = $this->succeeded($context);
        }

        $mark = match (true) {
            $status === DeploymentStatus::Succeeded => '✓ ',
            $switchedBack instanceof Release => '↩ ',
            default => '✗ ',
        };
        // The outcome goes to the run pages, to Telegram and to MCP answers.
        $outcome = $context->log->redactor->redact((string) $outcome);
        $context->log->note($mark.$outcome);
        $context->log->flush();

        $this->finish($deployment, $status, $outcome);
    }

    /**
     * Between steps: a cancel stops the run before the next step, and that
     * step says so (a retry picks up from it).
     *
     * @throws DeploymentCancelled
     */
    private function checkCancelled(DeployContext $context, Step $step): void
    {
        try {
            $context->throwIfCancelled();
        } catch (DeploymentCancelled $e) {
            $context->deployment->steps()->where('key', $step->key())->where('status', StepStatus::Pending)
                ->update(['status' => StepStatus::Cancelled, 'detail' => 'cancelled before it started']);
            $this->announce($context->deployment);

            throw $e;
        }
    }

    /**
     * A step a retry leaves out, since the run it retries got past it: done
     * there, or it didn't apply there either.
     */
    private function markDone(DeployContext $context, Step $step, ?DeploymentStep $there): void
    {
        $detail = match (true) {
            $there?->status === StepStatus::Succeeded => "done in #{$context->deployment->retry_of_id}",
            // A retry of a retry: the run that actually did it.
            str_starts_with((string) $there?->detail, 'done in #') => $there?->detail,
            default => null,
        };

        $context->deployment->steps()->where('key', $step->key())->update(['status' => StepStatus::Skipped, 'detail' => $detail]);
    }

    private function runStep(DeployContext $context, Step $step): void
    {
        /** @var DeploymentStep $row */
        $row = $context->deployment->steps()->where('key', $step->key())->firstOrFail();

        if (! $step->shouldRun($context)) {
            $row->update(['status' => StepStatus::Skipped]);
            $this->announce($context->deployment);

            return;
        }

        $context->log->forStep($step->key());
        if ($row->status !== StepStatus::Pending || $row->detail === null) {
            $context->log->note('▸ '.$step->label());
        }
        $row->update(['status' => StepStatus::Running, 'started_at' => $row->started_at ?? now()]);
        $this->announce($context->deployment);

        try {
            $detail = $step->run($context);
        } catch (WaitingForCi $e) {
            // Not a failure: the step runs again when the run is picked up.
            $row->update(['status' => StepStatus::Pending, 'detail' => Str::limit($e->getMessage(), 250)]);
            $context->log->flush();

            throw $e;
        } catch (Throwable $e) {
            // What a script printed on stderr can hold a secret: the step's
            // detail is masked like the log is.
            $message = $context->log->redactor->redact($e instanceof SshException ? $e->userMessage() : $e->getMessage());

            $row->update([
                'status' => $e instanceof DeploymentCancelled ? StepStatus::Cancelled : StepStatus::Failed,
                'finished_at' => now(),
                'detail' => Str::limit($message, 250),
                'exit_code' => $e instanceof StepFailed ? $e->exitCode : ($e instanceof SshException ? $e->exitCode : null),
            ]);

            if (! $e instanceof DeploymentCancelled) {
                $context->log->note('✗ '.$message);
            }
            $context->log->flush();
            $this->announce($context->deployment);

            throw $e;
        }

        $row->update(['status' => StepStatus::Succeeded, 'finished_at' => now(), 'detail' => $detail !== null ? Str::limit($detail, 250) : null]);
        $context->log->flush();
        $this->announce($context->deployment);
    }

    /**
     * The strategy ahead waits for CI: the run pauses and is picked up again
     * in a moment, for up to an hour.
     */
    private function wait(DeployContext $context, WaitingForCi $waiting): void
    {
        $deployment = $context->deployment;

        if ($deployment->created_at->lt(now()->subMinutes(self::MAX_WAIT_MINUTES))) {
            $deployment->steps()->where('status', StepStatus::Pending)->update(['status' => StepStatus::Skipped]);
            $outcome = 'CI didn’t publish anything within an hour. '.$waiting->getMessage();
            $context->log->note('✗ '.$outcome);
            $context->log->flush();
            $this->finish($deployment, DeploymentStatus::Failed, $outcome);

            return;
        }

        $deployment->forceFill(['status' => DeploymentStatus::WaitingCi, 'heartbeat_at' => now()])->save();
        $this->announce($deployment);

        RunDeployment::dispatch($deployment->id)->delay(now()->addSeconds(self::RECHECK_EVERY));
    }

    /**
     * Brings the release and the target in line with what's on the server now.
     */
    private function settle(DeployContext $context, DeploymentStatus $status): void
    {
        if ($context->deployment->kind === DeploymentKind::UndoConvert) {
            $this->settleUndo($context, $status);

            return;
        }

        $release = $context->memo['release'] ?? null;

        if (! $release instanceof Release) {
            return;
        }

        $target = $context->target;
        $previous = $context->memo['previous'] ?? null;
        $previousId = $previous instanceof Release ? $previous->id : null;
        $switchedBack = isset($context->memo['rolled_back']) || ($context->memo['undone'] ?? false);
        $wentLive = $status === DeploymentStatus::Succeeded || (($context->memo['live'] ?? false) && ! $switchedBack);

        $health = match (true) {
            $context->memo['healthy'] ?? false => TargetHealth::Healthy,
            ($context->memo['unhealthy'] ?? false) && ! $switchedBack => TargetHealth::Unhealthy,
            default => null,
        };

        if ($context->deployment->kind === DeploymentKind::Rollback) {
            if ($wentLive) {
                $target->update(array_filter([
                    'current_release_id' => $release->id,
                    'previous_release_id' => $previousId,
                    'last_deployed_at' => now(),
                    'health' => $health,
                ], fn ($value) => $value !== null));
            }

            return;
        }

        if (! $wentLive) {
            $release->update(['status' => ReleaseStatus::Failed]);

            return;
        }

        $release->update(['status' => ReleaseStatus::Ready, 'activated_at' => $release->activated_at ?? now()]);
        $target->update(array_filter([
            'current_release_id' => $release->id,
            'previous_release_id' => $previousId,
            // Adopting and converting record what's there; deploy.me didn't deploy it.
            'last_deployed_at' => in_array($context->deployment->kind, [DeploymentKind::Adopt, DeploymentKind::Convert], true) ? null : now(),
            'bootstrapped_at' => $target->bootstrapped_at ?? now(),
            'health' => $health,
        ], fn ($value) => $value !== null));
    }

    /**
     * What a finished run says, and what it changes on the target.
     */
    private function succeeded(DeployContext $context): string
    {
        $kind = $context->deployment->kind;

        if ($kind === DeploymentKind::Bootstrap) {
            $context->target->update(['bootstrapped_at' => now()]);
            $foreign = $context->memo['foreign'] ?? null;

            if ($foreign !== null) {
                $more = $foreign['count'] - count($foreign['names']);
                $names = implode(', ', $foreign['names']).($more > 0 ? " and {$more} more" : '');

                return "{$context->layout->root} is ready for deploys. It already held {$names} from an earlier deployment; those were left as they are."
                    .($context->layout->versionable ? ' Convert the folder to releases before the first deploy: it’s on the Targets tab.' : '');
            }

            return "{$context->layout->root} is ready for deploys.";
        }

        if ($kind === DeploymentKind::UndoConvert) {
            return "{$context->layout->root} is back the way it was before the conversion.";
        }

        if ($kind === DeploymentKind::Unlock) {
            ['broken' => $broken, 'holder' => $holder] = $context->memo['broken'] ?? ['broken' => false, 'holder' => ''];

            return match (true) {
                ! $broken => "{$context->layout->root} wasn’t locked.",
                $holder !== '' => "Broke the lock deploy #{$holder} held on {$context->layout->root}.",
                default => "Broke the lock on {$context->layout->root}.",
            };
        }

        $release = $context->memo['release'] ?? null;

        if (! $release instanceof Release) {
            return 'Done.';
        }

        if ($kind === DeploymentKind::Convert) {
            return $this->converted($context, $release);
        }

        $sha = $release->shortSha();

        if ($kind === DeploymentKind::Rollback) {
            return "Release {$release->name} is live again on {$context->server->name}.";
        }

        if ($kind === DeploymentKind::Adopt) {
            return ($sha !== null ? "{$sha} in {$context->layout->root}" : "What’s in {$context->layout->root}")
                .' is the live release now. The next deploy replaces it in place.';
        }

        if ($release->inReleasesFolder()) {
            return "Release {$release->name}".($sha !== null ? " ({$sha})" : '')." is live on {$context->server->name}.";
        }

        return ($sha !== null ? "{$sha} is live" : 'The files are live')." in {$context->layout->root}.";
    }

    /**
     * A conversion went through: the project shares what went to shared/,
     * the folder counts as converted, and the run keeps where its backup is.
     */
    private function converted(DeployContext $context, Release $release): string
    {
        $conversion = Conversion::of($context->deployment);

        $context->project->update(['shared_paths' => $conversion->sharedPaths]);
        $context->target->update(['folder_scan' => null, 'folder_scanned_at' => null]);
        $context->deployment->forceFill(['options' => [
            ...($context->deployment->options ?? []),
            'backup' => ".deploy/convert-{$release->name}/old",
        ]])->save();

        $toRelease = $conversion->toRelease();
        $toShared = count($conversion->shared());

        return "{$context->layout->root} deploys into releases/ now: {$toRelease} ".Str::plural('item', $toRelease)." moved into release {$release->name}"
            .($toShared > 0 ? " and {$toShared} into shared/" : '').'.';
    }

    /**
     * An undone conversion: its release is gone from the server, the folder
     * runs from its old layout again, and the project shares what it did before.
     */
    private function settleUndo(DeployContext $context, DeploymentStatus $status): void
    {
        $release = $context->memo['undoing'] ?? null;

        if ($status !== DeploymentStatus::Succeeded || ! $release instanceof Release) {
            return;
        }

        $release->update(['status' => ReleaseStatus::Pruned, 'pruned_at' => now()]);
        $context->target->update([
            'current_release_id' => $release->deployment?->previous_release_id,
            'previous_release_id' => null,
            'folder_scan' => null,
            'folder_scanned_at' => null,
        ]);

        $before = $release->deployment?->options['conversion'] ?? [];

        if (array_key_exists('shared_paths_before', $before)) {
            $context->project->update(['shared_paths' => $before['shared_paths_before']]);
        }
    }

    /**
     * @param  list<Step>  $steps
     */
    private function createSteps(Deployment $deployment, array $steps): void
    {
        foreach ($steps as $position => $step) {
            $deployment->steps()->firstOrCreate(['key' => $step->key()], ['label' => $step->label(), 'position' => $position]);
        }
    }

    private function finish(Deployment $deployment, DeploymentStatus $status, ?string $outcome): void
    {
        $deployment->forceFill(['status' => $status, 'outcome' => $outcome, 'finished_at' => now(), 'heartbeat_at' => now()])->save();
        $this->announce($deployment);

        // Only a failure says anything, and never at the cost of the run.
        rescue(fn () => Alert::deploymentFinished($deployment), report: false);
    }

    private function announce(Deployment $deployment): void
    {
        try {
            DeploymentUpdated::dispatch($deployment->id, $deployment->project_id, $deployment->status->value);
        } catch (Throwable $e) {
            Log::warning('Could not broadcast DeploymentUpdated', ['deployment' => $deployment->id, 'error' => $e->getMessage()]);
        }
    }
}
