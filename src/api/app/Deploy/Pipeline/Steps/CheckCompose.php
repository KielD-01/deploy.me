<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\Conversion\Conversion;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Converting a Docker project: Compose reads the stack from the release, with
 * its env files and mounts where the links put them, before current points
 * there.
 */
final class CheckCompose implements Step
{
    public function key(): string
    {
        return 'convert_compose';
    }

    public function label(): string
    {
        return 'Check the compose project';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return Compose::builtin($context->project)
            && ($context->memo['release'] ?? null) instanceof Release
            && ($context->memo['conversion'] ?? null) instanceof Conversion;
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];
        /** @var Conversion $conversion */
        $conversion = $context->memo['conversion'];

        $run = $context->script('deploy-convert', $conversion->vars($context, $release->name, 'compose'), timeout: 120)
            ->throwIfFailed('Compose can’t read the stack from the release.');

        $services = (int) $run->marker('services');

        return "{$services} ".Str::plural('service', $services).' read from the release';
    }
}
