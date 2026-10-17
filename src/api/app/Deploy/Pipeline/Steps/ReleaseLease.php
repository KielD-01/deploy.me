<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;

/**
 * Gives the folder's lock back, and removes a registry login the deploy
 * didn't get to use. A cleanup step: runs whatever happened before it, as
 * long as the lock was taken.
 */
final class ReleaseLease implements Step
{
    public const KEY = 'unlock';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Clean up';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return ! empty($context->memo['lease']);
    }

    public function run(DeployContext $context): ?string
    {
        if (isset($context->memo['registry_config'])) {
            $context->script('deploy-forget-login', ['DIR' => (string) $context->memo['registry_config']], timeout: StepTimeout::QUICK);
            unset($context->memo['registry_config']);
        }

        $context->script('deploy-unlock', [
            'TARGET' => $context->layout->root,
            'OWNER' => $context->deployment->id,
        ], timeout: StepTimeout::QUICK)->throwIfFailed('Couldn’t release the lock. The next deploy takes it over, since this run is over by then.');

        return null;
    }
}
