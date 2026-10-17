<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepTimeout;
use App\Enums\DeliveryStrategyKey;
use App\Models\Release;

/**
 * docker compose pull from the new release, whose .env pins its images, with
 * the deploy's registry login (removed right after).
 */
final class PullImages implements Step
{
    public const KEY = 'pull';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Pull images';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return $context->strategy === DeliveryStrategyKey::GhcrImage
            && Compose::builtin($context->project)
            && $context->release instanceof Release;
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->release;
        $services = $context->project->app_services ?? [];

        $context->script('deploy-compose', [
            ...Compose::vars($context->project, $release->path, 'pull', $services),
            'DOCKER_CONFIG_DIR' => (string) $context->registryConfig,
        ], timeout: StepTimeout::LONG)->throwIfFailed('Pulling the images failed.');

        $context->registryConfig = null;

        return $services !== [] ? implode(', ', $services) : 'every service';
    }
}
