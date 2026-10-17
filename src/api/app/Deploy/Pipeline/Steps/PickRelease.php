<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;

/**
 * A rollback: the release to make live again. With releases that's the folder
 * current points back at; deploying in place, it's the release's own image
 * pins going back into the folder (RestorePins), which only works for a
 * release that recorded them and a stack deploy.me runs.
 */
final class PickRelease implements Step
{
    public function key(): string
    {
        return 'pick';
    }

    public function label(): string
    {
        return 'Pick the release';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): ?string
    {
        $release = $context->target->releases()->whereKey((int) ($context->deployment->options['release_id'] ?? 0))->first();

        if ($release === null) {
            throw new StepFailed('That release isn’t on the server any more.');
        }

        if (! $release->canRollBackTo($context->project)) {
            throw new StepFailed($this->why($context));
        }

        $current = $context->target->currentRelease()->first();

        if ($current?->id === $release->id) {
            throw new StepFailed("Release {$release->name} is already live.");
        }

        $context->deployment->forceFill([
            'release_id' => $release->id,
            'previous_release_id' => $current?->id,
            'commit_sha' => $release->commit_sha,
            'commit_message' => $release->commit_message,
            'strategy' => $release->strategy?->value,
        ])->save();

        $context->memo['release'] = $release;
        $context->memo['previous'] = $current;
        // The images this release ran may have to be pulled again, which for
        // ghcr.io means logging in first.
        $context->memo['strategy'] = $release->strategy;

        return ($release->inReleasesFolder() ? "releases/{$release->name}" : $release->name)
            .($release->shortSha() !== null ? ' · '.$release->shortSha() : '');
    }

    /**
     * Why this one can't be rolled back to, in the words of what the project
     * would need instead.
     */
    private function why(DeployContext $context): string
    {
        if ($context->layout->versionable) {
            return 'That release isn’t on the server any more.';
        }

        if (! Compose::builtin($context->project)) {
            return 'This project deploys in place and deploy.me doesn’t run its stack, so there are no releases to switch between. Deploy an earlier commit instead.';
        }

        return 'That release didn’t record which images it ran, so there’s nothing to put back. Deploy its commit again instead.';
    }
}
