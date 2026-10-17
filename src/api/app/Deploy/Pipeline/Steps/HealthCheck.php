<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\StepTimeout;
use App\Models\Release;
use App\Platform\Ssh\SshErrors;

/**
 * Waits for the new release to be healthy within the project's timeout: a
 * Docker project's app services running (and healthy, where they have a
 * health check), then the app's health URL, asked from the server itself
 * (through its own edge when that answers, so a CDN's bot challenge can't
 * fail it), answering with a 2xx or 3xx, and last the project's own health
 * command, run on the server in the release's folder. Any of the three
 * failing fails the gate, so auto-rollback switches back.
 */
final class HealthCheck implements Step
{
    public function key(): string
    {
        return 'health';
    }

    public function label(): string
    {
        return 'Health check';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return (string) $context->project->health_url !== ''
            || (string) $context->project->health_command !== ''
            || (Compose::builtin($context->project) && ($context->memo['release'] ?? null) instanceof Release);
    }

    public function run(DeployContext $context): ?string
    {
        $timeout = max(10, (int) $context->project->health_timeout);
        $done = [];

        $release = $context->memo['release'] ?? null;
        if (Compose::builtin($context->project) && $release instanceof Release) {
            $done[] = self::waitForServices($context, $release, $timeout);
        }

        if (($url = (string) $context->project->health_url) !== '') {
            $run = $context->script('deploy-health', ['URL' => $url, 'TIMEOUT' => $timeout, 'INTERVAL' => 3], timeout: StepTimeout::cap($timeout + 30));

            if ($run->result->exitCode === 30) {
                $context->memo['unhealthy'] = true;

                throw new StepFailed("Health check failed after {$timeout} s: {$url} gave {$run->marker('last')}.", 30);
            }

            $run->throwIfFailed('The health check couldn’t run.');
            $done[] = "HTTP {$run->marker('code')} after {$run->marker('after')} s".($run->marker('via') === 'edge' ? ' from this server’s edge' : '');
        }

        if (($command = trim((string) $context->project->health_command)) !== '') {
            $done[] = self::runCommand($context, $command, $release instanceof Release ? $release : null, $timeout);
        }

        $context->memo['healthy'] = true;

        return implode(' · ', $done);
    }

    /**
     * The project's own check: a command on the server, in the release's
     * folder, with the release and commit in its environment. It has the last
     * word — whatever Docker and the URL said, a non-zero exit is unhealthy.
     *
     * @throws StepFailed
     */
    private static function runCommand(DeployContext $context, string $command, ?Release $release, int $timeout): string
    {
        $context->log->note('$ '.$command);

        $run = $context->script('deploy-hook', [
            'DIR' => $release?->path ?? $context->layout->root,
            'COMMAND' => $command,
            'RELEASE' => (string) $release?->name,
            'COMMIT' => (string) $release?->commit_sha,
        ], timeout: StepTimeout::cap($timeout + 30));

        if ($run->result->exitCode === 255) {
            throw SshErrors::from($run->result);
        }

        if (! $run->ok()) {
            $context->memo['unhealthy'] = true;

            throw new StepFailed("The project’s health command failed with exit code {$run->result->exitCode}.", $run->result->exitCode);
        }

        return 'health command ok';
    }

    /**
     * @throws StepFailed
     */
    public static function waitForServices(DeployContext $context, Release $release, int $timeout): string
    {
        $run = $context->script('deploy-compose-health', [
            ...Compose::vars($context->project, $release->path, 'ps', $context->project->app_services ?? []),
            'TIMEOUT' => $timeout,
            'INTERVAL' => 3,
        ], timeout: StepTimeout::cap($timeout + 30));

        if ($run->result->exitCode === 30) {
            $context->memo['unhealthy'] = true;

            throw new StepFailed("Health check failed after {$timeout} s: ".($run->marker('waiting') ?: 'the services didn’t come up').'.', 30);
        }

        $run->throwIfFailed('The health check couldn’t run.');

        return 'services up after '.$run->marker('after').' s';
    }
}
