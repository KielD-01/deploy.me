<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Conversion\Conversion;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Converting: hard links of everything that moves, in
 * .deploy/convert-<release>/old. Instant, and no extra room on the server.
 */
final class BackUpLayout implements Step
{
    public const KEY = 'convert_backup';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Back up the old layout';
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

        $run = $context->script('deploy-convert', $conversion->vars($context, $release->name, 'backup'), timeout: 1800)
            ->throwIfFailed('Couldn’t back up the old layout.');

        $count = (int) $run->marker('backed_up');

        return "{$count} ".Str::plural('item', $count)." as hard links in .deploy/convert-{$release->name}/old";
    }
}
