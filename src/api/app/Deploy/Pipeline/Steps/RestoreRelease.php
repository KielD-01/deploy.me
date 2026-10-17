<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\AlwaysRuns;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Enums\DeliveryStrategyKey;
use App\Enums\ReleaseStatus;

/**
 * A retry's first step, in place of resolving a commit: it picks up the
 * release the run it retries left on the server, with that run's commit,
 * strategy and previous release, and marks it building again. Whether the
 * release is still live decides whether the retry switches to it again.
 */
final class RestoreRelease implements AlwaysRuns, Step
{
    public const KEY = 'restore';

    public function __construct(private readonly ?string $name = null) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->name !== null ? "Reuse release {$this->name}" : 'Reuse the release';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): ?string
    {
        $deployment = $context->deployment;
        $origin = $deployment->retryOf()->with(['release', 'previousRelease'])->first();
        $release = $origin?->release;

        if ($release === null || $release->target_id !== $context->target->id) {
            throw new StepFailed('The run this retries left no release to pick up.');
        }

        if (! $release->inReleasesFolder() || $release->pruned_at !== null || $release->status === ReleaseStatus::Pruned) {
            throw new StepFailed("Release {$release->name} isn’t on the server any more. Deploy its commit again instead.");
        }

        $previous = $origin->previousRelease;
        $current = $context->target->current_release_id;

        // Switching to it again would take down whatever went live since.
        if ($current !== $release->id && $current !== $previous?->id) {
            throw new StepFailed('Another release went live since that run. Deploy its commit again instead.');
        }

        $strategy = $release->strategy ?? DeliveryStrategyKey::tryFrom((string) $origin->strategy)
            ?? throw new StepFailed('The run this retries has no delivery strategy on record.');

        $release->update(['status' => ReleaseStatus::Building, 'deployment_id' => $deployment->id]);
        $deployment->forceFill([
            'release_id' => $release->id,
            'previous_release_id' => $previous?->id,
            'strategy' => $strategy->value,
            'commit_sha' => $release->commit_sha,
            'commit_message' => $release->commit_message,
        ])->save();

        $context->memo['release'] = $release;
        $context->memo['previous'] = $previous;
        $context->memo['strategy'] = $strategy;
        $context->memo['live'] = $current === $release->id;

        return "releases/{$release->name}".($release->shortSha() !== null ? ' · '.$release->shortSha() : '').($context->memo['live'] ? ' · still live' : '');
    }
}
