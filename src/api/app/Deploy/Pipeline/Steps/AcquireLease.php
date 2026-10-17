<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\AlwaysRuns;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\StepTimeout;
use App\Deploy\ScriptRun;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;

/**
 * Locks the target's folder on the server, so two deploys (from here, or a
 * second deploy.me) never overlap. A lock one of this target's runs left
 * behind once it was over (it failed, was cancelled, stalled) is taken over
 * at once, since nothing will give it back. A lock older than two hours,
 * whoever holds it, belongs to a deploy that died and is taken over too.
 */
final class AcquireLease implements AlwaysRuns, Step
{
    public const KEY = 'lease';

    public const STALE_AFTER = 7200;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Acquire deploy lock';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): ?string
    {
        $orphan = null;
        $run = $this->lease($context);

        if ($run->result->exitCode === 20 && ($orphan = $this->orphaned($context, (string) $run->marker('holder'))) !== null) {
            $run = $this->lease($context, $orphan);
        }

        if ($run->result->exitCode === 20) {
            $minutes = intdiv((int) $run->marker('age'), 60);

            throw new StepFailed("Deploy #{$run->marker('holder')} has held the lock on this folder for {$minutes} min. Wait for it, or try again once it’s done.", 20);
        }

        $run->throwIfFailed('Couldn’t lock the folder.');
        $context->lease = true;

        return match (true) {
            $run->marker('lease') === 'taken_over' && $orphan !== null => "took over the lock #{$orphan->id} left behind ({$this->why($orphan)})",
            $run->marker('lease') === 'stolen' => 'took over a lock deploy #'.$run->marker('previous').' left behind',
            default => null,
        };
    }

    private function lease(DeployContext $context, ?Deployment $takeOver = null): ScriptRun
    {
        return $context->script('deploy-lease', [
            'TARGET' => $context->layout->root,
            'OWNER' => $context->deployment->id,
            'STALE_AFTER' => self::STALE_AFTER,
            // The script takes the lock over only while it still names this run.
            'TAKEOVER' => $takeOver !== null ? $takeOver->id : '',
        ], timeout: StepTimeout::QUICK);
    }

    /**
     * The run the lock names, when it's one of this target's and it's over.
     * Anything else (a run still going, an id from another deploy.me) waits
     * for the two hours.
     */
    private function orphaned(DeployContext $context, string $holder): ?Deployment
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/', $holder) !== 1) {
            return null;
        }

        $run = Deployment::query()->whereKey((int) $holder)->where('target_id', $context->target->id)->first();

        return $run !== null && ! $run->isActive() ? $run : null;
    }

    private function why(Deployment $run): string
    {
        return match ($run->status) {
            DeploymentStatus::Failed => 'it had failed',
            DeploymentStatus::Cancelled => 'it was cancelled',
            DeploymentStatus::Stalled => 'it had stalled',
            DeploymentStatus::RolledBack => 'it had rolled back',
            default => 'it had finished',
        };
    }
}
