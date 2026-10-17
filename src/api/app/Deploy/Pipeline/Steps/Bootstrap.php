<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;

/**
 * Creates the target's folders: .deploy/ always, releases/ and shared/ for
 * versionable projects.
 */
final class Bootstrap implements Step
{
    public function key(): string
    {
        return 'folders';
    }

    public function label(): string
    {
        return 'Create folders';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): ?string
    {
        $context->script('deploy-bootstrap', [
            'TARGET' => $context->layout->root,
            'VERSIONABLE' => $context->layout->versionable ? 1 : 0,
        ], timeout: 60)->throwIfFailed('Couldn’t create the folders.');

        return $context->layout->versionable ? 'releases/ and shared/' : 'the project folder';
    }
}
