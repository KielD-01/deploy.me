<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\AlwaysRuns;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Enums\Activation;
use App\Enums\DeliveryStrategyKey;
use App\Enums\DeploymentKind;
use App\Models\Credential;

/**
 * Logs the server in to ghcr.io for this deploy's pulls only: a docker config
 * of the deploy's own in .deploy/, which the pull (or the clean-up, when the
 * pull never ran) removes. A project's own deploy script pulls with it too,
 * when it rolls back as well. The token travels inside the script. A retry
 * logs in again: the login of the run it retries is gone.
 */
final class RegistryLogin implements AlwaysRuns, Step
{
    public const KEY = 'login';

    public const REGISTRY = 'ghcr.io';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Log in to ghcr.io';
    }

    public function shouldRun(DeployContext $context): bool
    {
        $project = $context->project;

        if (! $project->dockerized) {
            return false;
        }

        // A rollback resolves no strategy, but the project's own deploy script
        // pulls the release's images again.
        return ($context->memo['strategy'] ?? null) === DeliveryStrategyKey::GhcrImage
            || ($context->deployment->kind === DeploymentKind::Rollback
                && $project->activation === Activation::Script
                && collect($project->strategies())->contains(fn (array $strategy) => $strategy['key'] === DeliveryStrategyKey::GhcrImage && $strategy['enabled']));
    }

    public function run(DeployContext $context): ?string
    {
        $project = $context->project;
        $credential = $project->credential ?? Credential::forOwner((string) $project->github_owner)
            ?? throw new StepFailed("No GitHub token reaches {$project->github_owner} to pull its images.");

        $context->log->redactor->add($credential->token);
        $dir = $context->layout->deployDir().'/docker-'.$context->deployment->id;

        $context->script('deploy-registry-login', [
            'REGISTRY' => self::REGISTRY,
            'USERNAME' => $credential->login,
            'TOKEN' => $credential->token,
            'DIR' => $dir,
        ], timeout: 60)->throwIfFailed('ghcr.io refused the login. The token needs read:packages.');

        $context->memo['registry_config'] = $dir;

        return "as {$credential->login}";
    }
}
