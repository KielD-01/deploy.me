<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Models\Project;
use App\Models\Release;

/**
 * Points the new release's shared folders (storage/) and env files at
 * shared/. The first release seeds shared/ with its own copy of a folder.
 */
final class LinkShared implements Step
{
    public const KEY = 'shared';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Link shared files';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return $context->layout->versionable
            && ($context->memo['release'] ?? null) instanceof Release
            && ($context->project->sharedPaths() !== [] || $context->project->envFiles()->exists());
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];
        $dirs = $context->project->sharedPaths();
        $files = Project::normalizePaths($context->project->envFiles()->pluck('path')->all());

        $context->script('deploy-link-shared', [
            'RELEASE' => $release->path,
            'SHARED' => $context->layout->shared(),
            'DIRS' => implode("\n", $dirs),
            'FILES' => implode("\n", $files),
        ], timeout: 120)->throwIfFailed('Couldn’t link the shared files.');

        return implode(', ', [...$dirs, ...$files]);
    }
}
