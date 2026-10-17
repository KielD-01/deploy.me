<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Compose reads the stack from the new release, with the env files linked
 * into it and its own image pins: a compose file the release broke, a
 * variable nothing fills in or a file it names that isn't there stops the
 * deploy here, before anything is built, pulled, migrated or replaced.
 */
final class CheckStack implements Step
{
    public const KEY = 'stack';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Check the stack';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return Compose::builtin($context->project) && $context->release instanceof Release;
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->release;

        $run = $context->script('deploy-compose', Compose::vars($context->project, $release->path, 'config'), timeout: StepTimeout::CHECK)
            ->throwIfFailed('Compose can’t read the stack from the new release.');

        $services = (int) $run->marker('services');

        return "{$services} ".Str::plural('service', $services);
    }
}
