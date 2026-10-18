<?php

namespace App\Deploy\Pipeline\Steps;

use App\Actions\Deployments\StartAction;
use App\Deploy\Actions\ActionDefinition;
use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepCarriedOn;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\StepTimeout;
use App\Enums\HookRunIn;
use App\Platform\Ssh\SshErrors;

/**
 * One step of an action, as a step of its run: on the server in the live
 * release's folder (deploy-hook.sh), in a running container of a service
 * (compose exec) or in a one-off container with the edge's labels reset
 * (compose run), the way hooks run. The inputs reach the command as
 * environment variables over stdin. A step whose `if` input is off is
 * skipped; one that may fail is recorded as failed and the run goes on.
 *
 * @phpstan-import-type ActionStep from ActionDefinition
 */
final class RunActionStep implements Step
{
    // Each step of the action is a step of its own: action_step_<n>.
    public const KEY = 'action_step';

    public const PREFIX = self::KEY.'_';

    /**
     * @param  ActionStep  $step
     */
    public function __construct(private readonly int $number, private readonly array $step) {}

    public function key(): string
    {
        return self::PREFIX.$this->number;
    }

    public function label(): string
    {
        return mb_strimwidth($this->step['name'], 0, 120, '…');
    }

    public function shouldRun(DeployContext $context): bool
    {
        $if = $this->step['if'];

        // Booleans are never secret: the run's own inputs have them.
        return $if === null || (bool) ($context->action['values'][$if] ?? $context->deployment->options['inputs'][$if] ?? false);
    }

    public function run(DeployContext $context): string
    {
        $step = $this->step;
        $dir = $context->action['dir'] ?? StartAction::folder($context->target);
        $runIn = HookRunIn::from($step['run_in']);
        $where = ActionDefinition::where($step);
        $timeout = StepTimeout::cap($step['timeout']);
        $vars = [
            'ENV_VARS' => self::envVars([...($step['env_file'] ? ($context->action['env_file'] ?? []) : []), ...($context->action['env'] ?? [])]),
        ];

        if ($runIn === HookRunIn::Host) {
            $context->log->note('$ '.$step['run']);
            $run = $context->script('deploy-hook', [
                'DIR' => $dir,
                'COMMAND' => $step['run'],
                'RELEASE' => (string) $context->target->live()?->name,
                'COMMIT' => (string) $context->target->live()?->commit_sha,
                ...$vars,
            ], timeout: $timeout);
        } elseif ($context->project->dockerized) {
            $action = $runIn === HookRunIn::ServiceRun ? 'run' : 'exec';
            $run = $context->script('deploy-compose', [
                ...Compose::vars($context->project, $dir, $action, [(string) $step['service']], $step['run']),
                ...$vars,
            ], timeout: $timeout);
        } else {
            throw new StepFailed("{$step['name']} runs in the {$step['service']} service, but {$context->project->name} isn’t a Docker project here.");
        }

        if ($run->ok()) {
            return $where;
        }

        if ($run->result->exitCode === 255) {
            throw SshErrors::from($run->result);
        }

        if ($step['continue_on_error']) {
            $context->log->note("! {$step['name']} failed with exit code {$run->result->exitCode}; carrying on");

            throw new StepCarriedOn("{$where} · exit {$run->result->exitCode}, carried on", $run->result->exitCode);
        }

        throw new StepFailed("{$step['name']} failed with exit code {$run->result->exitCode}.", $run->result->exitCode);
    }

    /**
     * NAME=<base64 value> per line, as the scripts read ENV_VARS.
     *
     * @param  array<string, string>  $env
     */
    public static function envVars(array $env): string
    {
        $lines = [];

        foreach ($env as $name => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name) === 1) {
                $lines[] = $name.'='.base64_encode((string) $value);
            }
        }

        return implode("\n", $lines);
    }
}
