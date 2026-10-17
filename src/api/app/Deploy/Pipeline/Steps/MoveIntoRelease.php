<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Conversion\Conversion;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;
use App\Models\Release;

/**
 * Converting: creates the release's folder and moves the items into it and
 * into shared/. Each move is written down on the server before it happens, so
 * if anything stops the run, the cleanup puts back exactly what moved.
 */
final class MoveIntoRelease implements Step
{
    public const KEY = 'convert_move';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Move files';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return ($context->memo['release'] ?? null) instanceof Release && ($context->memo['conversion'] ?? null) instanceof Conversion;
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];
        /** @var Conversion $conversion */
        $conversion = $context->memo['conversion'];

        // From here on, a failure puts the old layout back.
        $context->memo['moving'] = true;

        $run = $context->script('deploy-convert', $conversion->vars($context, $release->name, 'move'), timeout: StepTimeout::TRANSFER)
            ->throwIfFailed('Moving the files failed.');

        $toRelease = (int) $run->marker('to_release');
        $toShared = (int) $run->marker('to_shared');

        return "{$toRelease} into releases/{$release->name}".($toShared > 0 ? ", {$toShared} into shared/" : '');
    }
}
