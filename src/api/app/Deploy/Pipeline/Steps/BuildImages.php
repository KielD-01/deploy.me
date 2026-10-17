<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;
use App\Deploy\ServerBuild;
use App\Enums\DeliveryStrategyKey;
use App\Models\Release;

/**
 * A Docker project shipped as files builds its images on the server, from
 * the new release. When the compose file only names an image (CI usually builds
 * it), the project's server builds say how, as an override the build alone reads.
 */
final class BuildImages implements Step
{
    public const KEY = 'build';

    // The strategies that ship files, so the server builds the images.
    public const STRATEGIES = [DeliveryStrategyKey::PushArchive, DeliveryStrategyKey::PushRsync, DeliveryStrategyKey::ServerGit];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Build images';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return Compose::builtin($context->project)
            && ($context->memo['release'] ?? null) instanceof Release
            && in_array($context->memo['strategy'] ?? null, self::STRATEGIES, true);
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];

        $vars = Compose::vars($context->project, $release->path, 'build');
        $override = ServerBuild::override($context->project, $release);

        if ($override !== null) {
            $context->script('deploy-write-file', [
                'FILE' => rtrim($release->path, '/').'/'.ServerBuild::FILE,
                'CONTENT' => $override,
                'MODE' => '0644',
            ], timeout: StepTimeout::SHORT)->throwIfFailed('Couldn’t write the build recipe into the release.');

            $vars['FILES'] .= "\n".ServerBuild::FILE;

            foreach ($context->project->serverBuilds() as $build) {
                $context->script('deploy-build-ignore', [
                    'DIR' => $release->path,
                    'DOCKERFILE' => $build['dockerfile'],
                    'CONTEXT' => $build['context'],
                    'EXCLUDE' => implode("\n", ServerBuild::excluded($context->project, $build['context'])),
                ], timeout: StepTimeout::SHORT)->throwIfFailed('Couldn’t keep the shared files out of the build.');
            }
        }

        $context->script('deploy-compose', $vars, timeout: StepTimeout::MAX)
            ->throwIfFailed('Building the images failed, so nothing changed on the server.');

        return $override !== null
            ? 'docker compose build · '.implode(', ', array_column($context->project->serverBuilds(), 'service'))
            : 'docker compose build';
    }
}
