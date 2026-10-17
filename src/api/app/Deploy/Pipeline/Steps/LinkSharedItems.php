<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Conversion\Conversion;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Converting: links what went to shared/ back into the release, where the
 * app and its compose files expect it.
 */
final class LinkSharedItems implements Step
{
    public const KEY = 'convert_link';

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
        $conversion = $context->memo['conversion'] ?? null;

        return ($context->memo['release'] ?? null) instanceof Release && $conversion instanceof Conversion && $conversion->shared() !== [];
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];
        /** @var Conversion $conversion */
        $conversion = $context->memo['conversion'];

        $context->script('deploy-convert', $conversion->vars($context, $release->name, 'link'), timeout: StepTimeout::MEDIUM)
            ->throwIfFailed('Couldn’t link the shared files.');

        return Str::limit(implode(', ', $conversion->shared()), 200);
    }
}
