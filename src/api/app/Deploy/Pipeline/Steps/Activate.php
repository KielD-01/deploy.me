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
    public const KEY = 'activate';

    // Converting only switches: the project's own deploy script doesn't run.
    public function __construct(private readonly string $label = 'Switch current', private readonly bool $switchOnly = false) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function shouldRun(DeployContext $context): bool
    {
        return ($context->memo['release'] ?? null) instanceof Release
            && ($context->layout->versionable || (! $this->switchOnly && self::usesScript($context)));
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

        if (! $this->switchOnly && self::usesScript($context)) {
            $context->memo['live'] = true;
            $done[] = self::runScript($context, $release);
        }

        return implode(' · ', $done);
    }

    /**
     * The project's own deploy script, from a release's folder. Switching back
     * runs the old release's again: current moving moves no container the
     * script started.
     */
    public static function runScript(DeployContext $context, Release $release): string
    {
        $script = (string) $context->project->activation_script;

        $context->script('deploy-run-script', [
            'DIR' => $release->path,
            'SCRIPT' => $script,
            'RELEASE' => $release->name,
            'COMMIT' => (string) $release->commit_sha,
            'TARGET' => $context->layout->root,
            // The deploy's registry login, so the script's own pulls work.
            'DOCKER_CONFIG_DIR' => (string) ($context->memo['registry_config'] ?? ''),
        ], timeout: 1800)->throwIfFailed("{$script} failed.");

        return "ran {$script}";
    }

    public static function switchTo(DeployContext $context, Release $release): void
    {
        $context->script('deploy-activate', [
            'TARGET' => $context->layout->root,
            'RELEASE' => $release->name,
        ], timeout: 60)->throwIfFailed("Couldn’t point current at {$release->name}.");
    }

    public static function usesScript(DeployContext $context): bool
    {
        return $context->project->activation === Activation::Script && (string) $context->project->activation_script !== '';
    }
}
