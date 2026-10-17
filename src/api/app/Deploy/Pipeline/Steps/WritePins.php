<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\Delivery\ImagePlan;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\StepTimeout;
use App\Enums\DeliveryStrategyKey;
use App\Models\Project;
use App\Models\Release;

/**
 * Pins the release to its own images: the tags CI published for its commit
 * (the GHCR strategy), or, for images the server builds, tags named after the
 * commit, so a rollback brings the old images back instead of the newest ones
 * under the same tag. The pins go in the release's .deployme-pins.env, which
 * Compose reads after the shared .env: nothing in .env changes, and nothing
 * secret leaves the server.
 */
final class WritePins implements Step
{
    public const KEY = 'pins';

    public const FILE = '.deployme-pins.env';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Pin images';
    }

    public function shouldRun(DeployContext $context): bool
    {
        $release = $context->release;
        $strategy = $context->strategy;

        if (! $release instanceof Release) {
            return false;
        }

        return $strategy === DeliveryStrategyKey::GhcrImage
            || (Compose::builtin($context->project) && in_array($strategy, BuildImages::STRATEGIES, true) && self::builtPins($context->project, $release) !== []);
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->release;

        $pins = $context->strategy === DeliveryStrategyKey::GhcrImage
            ? ($release->commit_sha !== null ? ImagePlan::for($context->project, $release->commit_sha)?->pins : null)
            : self::builtPins($context->project, $release);

        if ($pins === null || $pins === []) {
            throw new StepFailed('Couldn’t work out which images to pin this release to.');
        }

        $context->script('deploy-write-file', [
            'FILE' => rtrim($release->path, '/').'/'.self::FILE,
            'CONTENT' => self::withPins('', $pins),
            'MODE' => '0644',
        ], timeout: StepTimeout::SHORT)->throwIfFailed('Couldn’t write the image pins.');

        $release->update(['image_pins' => $pins]);

        return implode(' · ', array_map(fn (string $var, string $value) => "{$var}={$value}", array_keys($pins), $pins));
    }

    /**
     * Tags for the images the server builds: the compose file's tag variables
     * (IMAGE_TAG in `image: app:${IMAGE_TAG}`), named after the release's
     * commit, or after the release when it has none.
     *
     * @return array<string, string>
     */
    public static function builtPins(Project $project, Release $release): array
    {
        $file = Compose::files($project)[0] ?? null;
        $detection = $project->detected();
        $compose = collect($detection->composeFiles())->firstWhere('file', $file) ?? $detection->primaryCompose();
        $tag = $release->shortSha() ?? $release->name;
        $pins = [];

        foreach ($compose['pins'] ?? [] as $pin) {
            if (($pin['kind'] ?? null) === 'tag' && is_string($pin['var'] ?? null) && $pin['var'] !== '') {
                $pins[$pin['var']] = $tag;
            }
        }

        return $pins;
    }

    /**
     * An env file with each pin set: an existing line replaced, a missing one added.
     *
     * @param  array<string, string>  $pins
     */
    public static function withPins(string $env, array $pins): string
    {
        $lines = $env === '' ? [] : preg_split('/\R/', rtrim($env, "\r\n"));

        foreach ($pins as $var => $value) {
            $found = false;

            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*(export\s+)?'.preg_quote($var, '/').'\s*=/', $line) === 1) {
                    $lines[$i] = "{$var}={$value}";
                    $found = true;
                }
            }

            if (! $found) {
                $lines[] = "{$var}={$value}";
            }
        }

        return implode("\n", $lines)."\n";
    }
}
