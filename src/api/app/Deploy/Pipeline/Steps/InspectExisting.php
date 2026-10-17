<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\StepTimeout;
use App\Enums\ReleaseStatus;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Adopting: reads what an earlier deployment left in an in-place folder (the
 * commit, from git or the .deploy-state a deploy script of its own wrote, the
 * image tags its .env pins, the running stack) and records it as the live
 * release. Nothing on the server changes. The pins matter: they are what a
 * rollback to this release would put back.
 */
final class InspectExisting implements Step
{
    public const KEY = 'inspect';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Read what’s running';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): ?string
    {
        if ($context->layout->versionable) {
            throw new StepFailed('Adopting takes over a folder deployed in place. Switch Versionable off in the project’s settings first.');
        }

        $run = $context->script('deploy-inspect', [
            'TARGET' => $context->layout->root,
            'STACK' => $context->project->dockerized ? (string) $context->project->stack_name : '',
            // IMAGE_TAG is the convention a project without detected pins follows.
            'VARS' => $context->project->dockerized ? implode("\n", array_keys(Compose::pinKinds($context->project)) ?: ['IMAGE_TAG']) : '',
        ], timeout: StepTimeout::SHORT)->throwIfFailed('Couldn’t read the folder.');

        $sha = fn (?string $value) => preg_match('/^[0-9a-f]{40}$/', (string) $value) === 1 ? (string) $value : null;
        $commit = $sha($run->marker('commit'));
        $fromState = $commit === null ? $sha($run->marker('state_current')) : null;
        $commit ??= $fromState;
        $before = $sha($run->marker('state_previous'));
        $pins = self::pins((string) $run->marker('pins'));
        $previous = $context->target->currentRelease()->first();

        if ($before !== null) {
            $context->log->note('· The deployment before this one was '.substr($before, 0, 7).'; there is no release of it here to switch back to.');
        }

        $release = $context->target->releases()->create([
            'deployment_id' => $context->deployment->id,
            'name' => Release::nextName($context->target),
            'path' => $context->layout->root,
            'layout' => Release::IN_PLACE,
            'commit_sha' => $commit,
            'commit_message' => $run->marker('subject') !== null ? Str::limit((string) $run->marker('subject'), 250) : null,
            'image_pins' => $pins !== [] ? $pins : null,
            'status' => ReleaseStatus::Ready,
            'adopted' => true,
        ]);

        $context->deployment->forceFill([
            'release_id' => $release->id,
            'previous_release_id' => $previous?->id,
            'commit_sha' => $release->commit_sha,
            'commit_message' => $release->commit_message,
        ])->save();

        $context->release = $release;
        $context->previous = $previous;
        $context->live = true;

        $found = array_filter([
            $commit !== null
                ? substr($commit, 0, 7).(($branch = $run->marker('branch')) ? " on {$branch}" : '').($fromState !== null ? ' from .deploy-state' : '')
                : null,
            $pins !== [] ? implode(' · ', array_map(fn (string $var, string $value) => "{$var}={$value}", array_keys($pins), $pins)) : null,
            ($stack = $run->marker('stack')) ? "stack {$context->project->stack_name} {$stack}" : null,
        ]);

        return $found !== [] ? implode(' · ', $found) : ((int) $run->marker('files')).' items, not a git checkout';
    }

    /**
     * The pins the script read from the folder's env file: VAR=value, one per
     * "|". Anything that isn't a variable and a plain value is left out.
     *
     * @return array<string, string>
     */
    private static function pins(string $marker): array
    {
        $pins = [];

        foreach (array_filter(explode('|', $marker)) as $pair) {
            [$var, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $var) === 1 && preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/-]*$~', $value) === 1) {
                $pins[$var] = $value;
            }
        }

        return $pins;
    }
}
