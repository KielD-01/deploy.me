<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Enums\Activation;
use App\Models\Release;

/**
 * Makes the release live: current switches to it (atomically, where the
 * server has GNU mv), then the project's own deploy script runs if the
 * project uses one. In place, the script is the only thing to do.
 */
final class Activate implements Step
{
    // Converting only switches: the project's own deploy script doesn't run.
    public function __construct(private readonly string $label = 'Switch current', private readonly bool $switchOnly = false) {}

    public function key(): string
    {
        return 'activate';
    }

    public function label(): string
    {
        return $this->label;
    }

    public function shouldRun(DeployContext $context): bool
    {
        return ($context->memo['release'] ?? null) instanceof Release
            && ($context->layout->versionable || (! $this->switchOnly && $this->usesScript($context)));
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];
        $done = [];

        if ($context->layout->versionable) {
            self::switchTo($context, $release);
            $context->memo['live'] = true;
            $done[] = "current → releases/{$release->name}";
        }

        if (! $this->switchOnly && $this->usesScript($context)) {
            $script = (string) $context->project->activation_script;
            $context->memo['live'] = true;

            $context->script('deploy-run-script', [
                'DIR' => $release->path,
                'SCRIPT' => $script,
                'RELEASE' => $release->name,
                'COMMIT' => (string) $release->commit_sha,
                'TARGET' => $context->layout->root,
                // The deploy's registry login, so the script's own pulls work.
                'DOCKER_CONFIG_DIR' => (string) ($context->memo['registry_config'] ?? ''),
            ], timeout: 1800)->throwIfFailed("{$script} failed.");

            $done[] = "ran {$script}";
        }

        return implode(' · ', $done);
    }

    public static function switchTo(DeployContext $context, Release $release): void
    {
        $context->script('deploy-activate', [
            'TARGET' => $context->layout->root,
            'RELEASE' => $release->name,
        ], timeout: 60)->throwIfFailed("Couldn’t point current at {$release->name}.");
    }

    private function usesScript(DeployContext $context): bool
    {
        return $context->project->activation === Activation::Script && (string) $context->project->activation_script !== '';
    }
}
