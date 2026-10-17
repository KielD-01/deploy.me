<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Models\Release;

/**
 * Cleanup: once the new release went live, the release that was live before
 * goes back — current switches to its folder, or, deploying in place, its
 * image pins go back where Compose reads them — and a Docker project's app
 * services are recreated from it. A failure switches back if the project
 * rolls back automatically; a cancel always does, since whoever cancelled
 * asked for the deploy to stop, not to stay half done.
 */
final class RollBack implements Step
{
    public function __construct(private readonly ?string $to = null) {}

    public function key(): string
    {
        return 'rollback';
    }

    public function label(): string
    {
        return $this->to !== null ? "Switch back to {$this->to}" : 'Switch back';
    }

    public function shouldRun(DeployContext $context): bool
    {
        $previous = $context->memo['previous'] ?? null;
        $asked = ($context->memo['cancelled'] ?? false)
            || (($context->memo['failed'] ?? false) && $context->project->auto_rollback);

        return $asked
            && ($context->memo['live'] ?? false)
            && $previous instanceof Release
            && $previous->canRollBackTo($context->project);
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $previous */
        $previous = $context->memo['previous'];

        if ($context->layout->versionable) {
            Activate::switchTo($context, $previous);
            $done = ["current → releases/{$previous->name}"];
        } else {
            $done = ['images → '.RestorePins::write($context, $previous)];
        }

        $context->memo['rolled_back'] = $previous;

        if (Compose::builtin($context->project)) {
            RecreateServices::recreate($context, $previous);
            $done[] = 'app services recreated';

            try {
                $done[] = HealthCheck::waitForServices($context, $previous, max(10, (int) $context->project->health_timeout));
            } catch (StepFailed $e) {
                $context->log->note('! '.$e->getMessage());
                $done[] = 'still not healthy';
            }
        }

        return implode(' · ', $done);
    }
}
