<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\Conversion\Conversion;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Models\Release;

/**
 * Converting, when you chose to: the app services are recreated from the
 * release, and have to pass the health check. Otherwise they keep running,
 * and the next deploy recreates them from releases/.
 */
final class RestartStack implements Step
{
    public const KEY = 'convert_restart';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Restart the stack';
    }

    public function shouldRun(DeployContext $context): bool
    {
        $conversion = $context->memo['conversion'] ?? null;

        return Compose::builtin($context->project)
            && ($context->memo['release'] ?? null) instanceof Release
            && $conversion instanceof Conversion
            && $conversion->restart;
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];

        $context->memo['restarted'] = true;
        RecreateServices::recreate($context, $release);
        $health = (new HealthCheck)->run($context);

        return 'app services recreated from the release'.($health !== null && $health !== '' ? " · {$health}" : '');
    }
}
