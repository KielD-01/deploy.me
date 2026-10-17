<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\AlwaysRuns;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Enums\DeploymentKind;
use App\Support\Format;
use Illuminate\Support\Str;

/**
 * One round trip before anything changes: the folder can be created and
 * written, there's room, the tools are there, and for Docker projects that
 * Docker works, the networks the stack joins are there, and no other Compose
 * project runs from the folder.
 */
final class Preflight implements AlwaysRuns, Step
{
    public const MIN_FREE = 2 * 1024 ** 3;

    public function key(): string
    {
        return 'preflight';
    }

    public function label(): string
    {
        return 'Pre-flight checks';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): ?string
    {
        if ($context->server->isReserved($context->layout->root)) {
            throw new StepFailed("{$context->layout->root} is reserved for the shared infrastructure.");
        }

        $run = $context->script('deploy-preflight', [
            'TARGET' => $context->layout->root,
            'NEED_DOCKER' => $context->project->dockerized ? 1 : 0,
            // Deploys upload with rsync; preparing and rollbacks don't.
            'NEED_RSYNC' => in_array($context->deployment->kind, [DeploymentKind::Deploy, DeploymentKind::Redeploy], true) ? 1 : 0,
            'STACK' => (string) $context->project->stack_name,
            'MIN_FREE' => self::MIN_FREE,
            'VERSIONABLE' => $context->layout->versionable ? 1 : 0,
            // Compose never creates these, so `up` would stop on a missing one.
            'NETWORKS' => $context->project->dockerized ? implode("\n", Compose::externalNetworks($context->project)) : '',
        ], timeout: 60)->throwIfFailed('The pre-flight checks failed.');

        $parts = [Format::bytes((int) $run->marker('free')).' free'];

        // Files from an earlier, non-deploy.me deployment: kept, and mentioned.
        if (($foreign = $run->marker('foreign')) !== null) {
            $context->memo['foreign'] = [
                'names' => array_values(array_filter(explode('|', $foreign))),
                'count' => (int) $run->marker('foreign_count'),
            ];
            $parts[] = 'existing files kept';
            $this->unconverted($context, $context->memo['foreign']);
        }

        if ($context->project->dockerized) {
            $parts[] = 'compose v'.ltrim((string) $run->marker('compose'), 'v');

            if (($other = $run->marker('drift')) !== null) {
                $this->drifted($context, $other);
                $parts[] = "{$other} runs here";
            } else {
                $parts[] = 'no drift';
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Another Compose project already runs from the folder, so the stack name
     * isn't the one that started it. A run that brings the stack up would
     * start a second one beside it, with volumes of its own: the database
     * that is running would not be the one the new containers talk to. Those
     * runs stop here, with the two ways out; the rest only say so.
     *
     * @throws StepFailed
     */
    private function drifted(DeployContext $context, string $other): void
    {
        $root = $context->layout->root;
        $stack = (string) $context->project->stack_name;
        $startsIt = Compose::builtin($context->project)
            && in_array($context->deployment->kind, [DeploymentKind::Deploy, DeploymentKind::Redeploy, DeploymentKind::Rollback], true);

        if (! $startsIt) {
            $context->log->note("! Compose project {$other} already runs from {$root}, not {$stack}. A deploy will stop until the stack name matches it.");

            return;
        }

        throw new StepFailed(
            "Compose project {$other} already runs from {$root}, but this project’s stack is {$stack}. "
            ."Bringing {$stack} up beside it would give it volumes of its own, so nothing was changed. "
            ."Set the stack name to {$other} in the project’s settings, or take the old stack down on the server (docker compose -p {$other} down).",
            18,
        );
    }

    /**
     * Releases are how the project deploys, yet the folder still runs from an
     * earlier deployment's files: the Targets tab offers to convert it, and a
     * deploy waits for that, since it would start the stack without them.
     *
     * @param  array{names: list<string>, count: int}  $foreign
     *
     * @throws StepFailed
     */
    private function unconverted(DeployContext $context, array $foreign): void
    {
        if (! $context->layout->versionable || ($context->target->currentRelease()->first()?->inReleasesFolder() ?? false)) {
            return;
        }

        $context->target->forceFill(['folder_scan' => [
            ...($context->target->folder_scan ?? []),
            'foreign' => [...$foreign, 'seen_at' => now()->toIso8601String()],
        ]])->save();

        if (in_array($context->deployment->kind, [DeploymentKind::Deploy, DeploymentKind::Redeploy], true)) {
            $count = $foreign['count'];

            throw new StepFailed("{$context->layout->root} still holds an earlier deployment ({$count} ".Str::plural('item', $count).'). Convert it to releases first: it’s on the project’s Targets tab.', 17);
        }
    }
}
