<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\Conversion\Conversion;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Converting a Docker project: Compose reads the stack from the release, with
 * its env files and mounts where the links put them, before current points
 * there.
 */
final class CheckCompose implements Step
{
    public const KEY = 'convert_compose';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Check the compose project';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return Compose::builtin($context->project)
            && $context->release instanceof Release
            && $context->conversion instanceof Conversion;
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->release;
        /** @var Conversion $conversion */
        $conversion = $context->conversion;

        $run = $context->script('deploy-convert', $conversion->vars($context, $release->name, 'compose'), timeout: StepTimeout::CHECK)
            ->throwIfFailed('Compose can’t read the stack from the release.');

        $services = (int) $run->marker('services');

        return "{$services} ".Str::plural('service', $services).' read from the release';
    }
}
