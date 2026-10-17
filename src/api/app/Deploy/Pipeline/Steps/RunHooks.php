<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\StepTimeout;
use App\Enums\HookPhase;
use App\Enums\HookRunIn;
use App\Models\Release;
use App\Platform\Ssh\SshErrors;
use Illuminate\Support\Str;

/**
 * Runs the project's hooks for one phase on the server, in the release's
 * folder, in order. A failing hook stops the deploy unless it's marked to
 * carry on.
 */
final class RunHooks implements Step
{
    public function __construct(private readonly HookPhase $phase) {}

    public function key(): string
    {
        return 'hooks_'.$this->phase->value;
    }

    public function label(): string
    {
        return match ($this->phase) {
            HookPhase::AfterUpload => 'After-upload hooks',
            HookPhase::Release => 'Release hooks',
            HookPhase::AfterActivate => 'After-activate hooks',
        };
    }

    public function shouldRun(DeployContext $context): bool
    {
        return ($context->memo['release'] ?? null) instanceof Release
            && $context->project->hooks()->where('phase', $this->phase->value)->exists();
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];
        $ran = 0;
        $failed = 0;

        foreach ($context->project->hooks()->where('phase', $this->phase->value)->get() as $hook) {
            $name = $hook->name ?: Str::limit($hook->command, 40);

            $timeout = StepTimeout::cap((int) ($hook->timeout ?: 600));

            if ($hook->run_in === HookRunIn::Host) {
                $context->log->note('$ '.$hook->command);
                $run = $context->script('deploy-hook', [
                    'DIR' => $release->path,
                    'COMMAND' => $hook->command,
                    'RELEASE' => $release->name,
                    'COMMIT' => (string) $release->commit_sha,
                ], timeout: $timeout);
            } elseif ($context->project->dockerized && (string) $hook->service !== '') {
                $action = $hook->run_in === HookRunIn::ServiceRun ? 'run' : 'exec';
                $run = $context->script('deploy-compose', Compose::vars($context->project, $release->path, $action, [(string) $hook->service], $hook->command), timeout: $timeout);
            } else {
                $context->log->note("! {$name} runs in a Docker service, but the project isn’t dockerized; skipped");

                continue;
            }

            if ($run->ok()) {
                $ran++;

                continue;
            }

            if ($run->result->exitCode === 255) {
                throw SshErrors::from($run->result);
            }

            if (! $hook->continue_on_error) {
                throw new StepFailed("{$name} failed with exit code {$run->result->exitCode}.", $run->result->exitCode);
            }

            $context->log->note("! {$name} failed with exit code {$run->result->exitCode}; carrying on");
            $failed++;
        }

        return "{$ran} ran".($failed > 0 ? " · {$failed} failed" : '');
    }
}
