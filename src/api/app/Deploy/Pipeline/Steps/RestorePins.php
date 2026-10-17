<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Enums\DeploymentKind;
use App\Models\Release;

/**
 * Rolling back a project that deploys in place: there are no release folders
 * to point current at, so the release's own image pins go back into
 * .deployme-pins.env and the stack comes up on them again. For a project
 * whose code lives in the image that is the whole rollback — which is why
 * only a release that recorded its pins can be rolled back to.
 */
final class RestorePins implements Step
{
    public function key(): string
    {
        return 'restore_pins';
    }

    public function label(): string
    {
        return 'Put the images back';
    }

    public function shouldRun(DeployContext $context): bool
    {
        $release = $context->memo['release'] ?? null;

        return $context->deployment->kind === DeploymentKind::Rollback
            && ! $context->layout->versionable
            && Compose::builtin($context->project)
            && $release instanceof Release
            && $release->canPinBack();
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];

        return self::write($context, $release);
    }

    /**
     * Writes a release's pins where Compose reads them, over whatever is
     * there now. The clean-up step uses this too, to put the release that was
     * live back after a deploy in place went wrong.
     */
    public static function write(DeployContext $context, Release $release): string
    {
        $pins = (array) ($release->image_pins ?? []);

        $context->script('deploy-write-file', [
            'FILE' => rtrim($release->path, '/').'/'.WritePins::FILE,
            'CONTENT' => WritePins::withPins('', $pins),
            'MODE' => '0644',
        ], timeout: 60)->throwIfFailed('Couldn’t put the release’s image pins back.');

        return implode(' · ', array_map(fn (string $var, string $value) => "{$var}={$value}", array_keys($pins), $pins));
    }
}
