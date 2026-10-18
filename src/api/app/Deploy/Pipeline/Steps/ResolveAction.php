<?php

namespace App\Deploy\Pipeline\Steps;

use App\Actions\Deployments\StartAction;
use App\Deploy\Actions\ActionDefinition;
use App\Deploy\Actions\ActionInputs;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Models\ProjectEnvFile;
use App\Support\DotEnv;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The first step of an action run, once the folder is locked: the inputs
 * (the secret ones decrypted, masked in the log from here on, and dropped
 * from the run so they're kept nowhere), the folder the steps run in (the
 * live release's, as it is now) and the env file's variables for the steps
 * that ask for them.
 */
final class ResolveAction implements Step
{
    public const KEY = 'action';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Resolve the action';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): string
    {
        $options = $context->deployment->options ?? [];
        $definition = ActionDefinition::of($options['definition'] ?? null) ?? throw new StepFailed('The run doesn’t say what to run.');

        $secrets = [];
        if (is_string($options['secrets'] ?? null)) {
            try {
                $secrets = (array) json_decode(Crypt::decryptString($options['secrets']), true);
            } catch (DecryptException) {
                throw new StepFailed('The secret inputs can’t be read (APP_KEY changed since the run was queued). Run it again.');
            }

            // Kept until now so the queued run had them; nowhere from here on.
            unset($options['secrets']);
            $context->deployment->forceFill(['options' => $options])->save();
        }

        foreach ($secrets as $value) {
            $context->log->redactor->add((string) $value);
        }

        $values = [...($options['inputs'] ?? []), ...$secrets];
        $context->target->load('currentRelease');
        $dir = StartAction::folder($context->target);

        $context->action = [
            'values' => $values,
            'env' => ActionInputs::env($definition, $values),
            'dir' => $dir,
            'env_file' => collect($definition->steps())->contains('env_file', true) ? $this->envFile($context) : [],
        ];

        $from = ($options['source'] ?? null) === 'repo'
            ? ($options['file'] ?? 'the repository').(isset($options['commit']) ? ' @ '.substr((string) $options['commit'], 0, 7) : '')
            : 'made here';

        $context->log->note("{$definition->name()} · {$from}");
        $context->log->note('inputs  '.ActionInputs::line($options['shown'] ?? []));
        $context->log->note("folder  {$dir}");

        return "{$from} · in {$dir}";
    }

    /**
     * The variables of the project's env file (.env, else the first one),
     * for the steps marked env-file. Its secrets are masked in the log
     * already, as every run's are.
     *
     * @return array<string, string>
     */
    private function envFile(DeployContext $context): array
    {
        $files = ProjectEnvFile::query()->where('project_id', $context->project->id)->orderBy('path')->get();
        $file = $files->first(fn (ProjectEnvFile $file) => basename($file->path) === '.env') ?? $files->first();

        if ($file === null) {
            $context->log->note('! A step asks for the env file’s variables, but the project has no env file here.');

            return [];
        }

        return array_filter(DotEnv::parse($file->content), fn (string $key) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1, ARRAY_FILTER_USE_KEY);
    }
}
