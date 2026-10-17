<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Enums\DeploymentKind;
use App\Models\Release;
use App\Platform\Ssh\Exceptions\SshException;

/**
 * Writes .deploy/state.json, so the server describes itself: which project
 * lives here, how it's deployed, and which release is live.
 */
final class RecordState implements Step
{
    public const KEY = 'state';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Record state';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): ?string
    {
        $project = $context->project;
        $state = [
            'v' => 1,
            'managed_by' => 'deploy.me',
            'project' => $project->slug,
            'mode' => [
                'versionable' => $project->versionable,
                'dockerized' => $project->dockerized,
                'stack' => $project->dockerized ? $project->stack_name : null,
            ],
        ];

        // Preparing writes the file once; a later prepare leaves a deployed state alone.
        if ($context->deployment->kind === DeploymentKind::Bootstrap) {
            $context->script('deploy-state', [
                'TARGET' => $context->layout->root,
                'STATE' => $this->json($state + ['bootstrapped_at' => now()->toIso8601String(), 'current' => null, 'previous' => null]),
                'ONLY_IF_MISSING' => 1,
            ], timeout: 30)->throwIfFailed('Couldn’t write .deploy/state.json.');

            return null;
        }

        $release = $context->memo['release'] ?? null;
        $previous = $context->memo['previous'] ?? null;
        $state['current'] = $release instanceof Release ? [
            'release' => $release->name,
            'layout' => $release->layout,
            'commit' => $release->commit_sha,
            'adopted' => $release->adopted,
            'deployment' => $context->deployment->id,
            'deployed_at' => now()->toIso8601String(),
        ] : null;
        $state['previous'] = $previous instanceof Release ? ['release' => $previous->name, 'commit' => $previous->commit_sha] : null;

        // The release is live by now: a stale state file isn't worth undoing that.
        try {
            $context->script('deploy-state', [
                'TARGET' => $context->layout->root,
                'STATE' => $this->json($state),
                'ONLY_IF_MISSING' => 0,
            ], timeout: 30)->throwIfFailed('Couldn’t write .deploy/state.json.');
        } catch (StepFailed|SshException $e) {
            $context->log->note('! '.($e instanceof SshException ? $e->userMessage() : $e->getMessage()));

            return 'couldn’t update .deploy/state.json';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function json(array $state): string
    {
        return (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
