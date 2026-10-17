<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\Conversion\Conversion;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\StepTimeout;
use App\Enums\DeploymentKind;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Puts a converted folder's items back where they were, from the moves the
 * conversion wrote down: the cleanup of a conversion that stopped halfway,
 * and the whole of an undo run. When the stack was restarted from the
 * release, it's recreated from the old folder again.
 */
final class UndoConversion implements Step
{
    public const KEY = 'convert_undo';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Put the old layout back';
    }

    public function shouldRun(DeployContext $context): bool
    {
        if ($context->deployment->kind === DeploymentKind::UndoConvert) {
            return true;
        }

        return $context->moving && ! $context->completed;
    }

    public function run(DeployContext $context): ?string
    {
        [$release, $restarted] = $context->deployment->kind === DeploymentKind::UndoConvert
            ? $this->converted($context)
            : [$context->release, $context->restarted];

        $run = $context->script('deploy-convert', Conversion::bare($context->layout->root, $release->name, 'undo'), timeout: StepTimeout::TRANSFER)
            ->throwIfFailed('Couldn’t put the old layout back.');

        $context->undone = true;
        $back = (int) $run->marker('back');
        $done = ["{$back} ".Str::plural('item', $back).' back'];

        if ($restarted && Compose::builtin($context->project)) {
            $context->script('deploy-compose', Compose::vars($context->project, $context->layout->root, 'recreate', $context->project->app_services ?? []), timeout: StepTimeout::TRANSFER)
                ->throwIfFailed('Recreating the app services from the old folder failed.');
            $done[] = 'app services recreated from the old folder';
        }

        return implode(' · ', $done);
    }

    /**
     * An undo run: the converted release it takes back, while it's still live.
     *
     * @return array{0: Release, 1: bool}
     *
     * @throws StepFailed
     */
    private function converted(DeployContext $context): array
    {
        $release = $context->target->releases()->whereKey((int) ($context->deployment->options['release_id'] ?? 0))->with('deployment')->first();

        if ($release === null || $release->deployment?->kind !== DeploymentKind::Convert) {
            throw new StepFailed('That release didn’t come from converting this folder.');
        }

        if ($context->target->current_release_id !== $release->id) {
            throw new StepFailed("Release {$release->name} isn’t live any more, so the conversion can’t be undone.");
        }

        $context->undoing = $release;

        return [$release, (bool) ($release->deployment->options['conversion']['restart'] ?? false)];
    }
}
