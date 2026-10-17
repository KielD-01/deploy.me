<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Conversion\Conversion;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Converting: the folder is still as the plan saw it (nothing new, nothing
 * gone), what moves is on the folder's own filesystem, and the server has
 * GNU cp and mv. Then the first release gets its name.
 */
final class CheckFolder implements Step
{
    public const KEY = 'convert_check';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Check the folder';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): ?string
    {
        $conversion = Conversion::of($context->deployment);
        $target = $context->target;
        $name = Release::nextName($target);

        $context->script('deploy-convert', $conversion->vars($context, $name, 'check'), timeout: StepTimeout::CHECK)
            ->throwIfFailed('The folder can’t be converted as planned.');

        $previous = $target->currentRelease()->first();
        $release = $target->releases()->create([
            'deployment_id' => $context->deployment->id,
            'name' => $name,
            'path' => $context->layout->release($name),
            'layout' => Release::IN_RELEASES,
            'commit_sha' => $conversion->commit,
            'commit_message' => $conversion->message,
        ]);

        $context->deployment->forceFill([
            'release_id' => $release->id,
            'previous_release_id' => $previous?->id,
            'commit_sha' => $conversion->commit,
            'commit_message' => $conversion->message,
        ])->save();

        $context->release = $release;
        $context->previous = $previous;
        $context->conversion = $conversion;

        $moving = count(array_filter($conversion->items, fn (array $item) => ! str_contains($item['path'], '/')));

        return "{$moving} ".Str::plural('item', $moving).' to move'.($conversion->stays !== [] ? ' · '.count($conversion->stays).' stay' : '');
    }
}
