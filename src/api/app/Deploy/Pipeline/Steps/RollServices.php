<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;
use App\Models\Release;

/**
 * Blue/green: starts the release's containers beside the ones serving now,
 * waits for their health check, then retires the old ones. A colour that never
 * turns healthy is removed again and the old one keeps serving, so a deploy
 * that fails here costs no downtime.
 */
final class RollServices implements Step
{
    public const KEY = 'roll';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Start the new colour';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return Compose::builtin($context->project)
            && $context->project->blue_green
            && ($context->memo['release'] ?? null) instanceof Release
            && Compose::rollable($context->project) !== [];
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];
        $services = Compose::rollable($context->project);
        $color = self::nextColor($context, $release);
        $wait = max(10, (int) $context->project->health_timeout);

        $run = $context->script('deploy-compose-roll', [
            ...Compose::vars($context->project, $release->path, 'roll', $services),
            'TIMEOUT' => (string) $wait,
            'COLOR' => $color,
        ], timeout: StepTimeout::cap(RecreateServices::timeout($context->project) + $wait))
            ->throwIfFailed('The new colour didn’t come up, so the old one is still serving.');

        // The new containers take the traffic from here. A service this release's
        // compose file doesn't have was left to the recreate step.
        $rolled = $run->marker('rolled');
        $context->memo['live'] = true;
        $context->memo['rolled'] = $rolled === null ? $services : array_values(array_filter(explode(',', $rolled)));
        $release->update(['color' => $color]);

        return sprintf('%s · %s started, %s retired', $color, $run->marker('started'), $run->marker('retired'));
    }

    /**
     * The colour the deploy before this one didn't use.
     */
    private static function nextColor(DeployContext $context, Release $release): string
    {
        $last = $context->target->releases()
            ->whereNotNull('color')
            ->whereKeyNot($release->getKey())
            ->latest('id')
            ->value('color');

        return $last === 'blue' ? 'green' : 'blue';
    }
}
