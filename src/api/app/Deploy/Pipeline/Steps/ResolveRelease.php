<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Delivery\CommitOnBranch;
use App\Deploy\Delivery\DeliveryResolver;
use App\Deploy\Delivery\VerdictState;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\WaitingForCi;
use App\Enums\DeliveryStrategyKey;
use App\Enums\DeploymentStatus;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Picks the delivery strategy and the commit, and opens the release the rest
 * of the run fills in. When the strategy ahead of the rest waits for CI, the
 * run waits too, holding nothing on the server.
 */
final class ResolveRelease implements Step
{
    public const KEY = 'resolve';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Resolve commit';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): ?string
    {
        $deployment = $context->deployment;
        $options = $deployment->options ?? [];
        $commit = $options['commit'] ?? null;

        if ($commit !== null && preg_match('/^[0-9a-f]{7,40}$/', (string) $commit) !== 1) {
            throw new StepFailed("“{$commit}” isn’t a commit id.");
        }

        // "Deploy now with…" picks one strategy by hand.
        $only = DeliveryStrategyKey::tryFrom((string) ($options['strategy'] ?? ''));
        $resolution = app(DeliveryResolver::class)->resolve($context->project, $commit, $only);

        // A waiting run asks again every 20 s; the log only hears about changes.
        $gist = fn (?array $trace) => array_map(fn (array $entry) => [$entry['key'] ?? null, $entry['state'] ?? null, $entry['reason'] ?? null], $trace ?? []);
        if ($gist($resolution->trace) !== $gist($deployment->strategy_trace)) {
            foreach ($resolution->trace as $entry) {
                $mark = match ($entry['state']) {
                    VerdictState::Applicable->value => '✓',
                    VerdictState::Pending->value => '…',
                    VerdictState::Error->value => '!',
                    VerdictState::Off->value, VerdictState::NotNeeded->value => '·',
                    default => '✗',
                };
                $context->log->note("{$mark} {$entry['label']}: {$entry['reason']}");
            }
        }

        $verdict = $resolution->verdict ?? $resolution->pending;
        $deployment->forceFill([
            'strategy' => $resolution->strategy?->value,
            'strategy_trace' => $resolution->trace,
            'commit_sha' => $verdict?->commit,
            'commit_message' => $verdict?->commitMessage !== null ? Str::limit($verdict->commitMessage, 250) : null,
        ])->save();

        if ($resolution->waiting()) {
            throw new WaitingForCi($resolution);
        }

        if (! $resolution->found()) {
            throw new StepFailed($resolution->reason());
        }

        // A commit an agent or CI named is shipped only from the branch.
        if ($commit !== null && ($refusal = app(CommitOnBranch::class)->refusal($context->project, (string) ($deployment->commit_sha ?? $commit), (string) $deployment->trigger)) !== null) {
            throw new StepFailed($refusal);
        }

        if ($deployment->status === DeploymentStatus::WaitingCi) {
            $deployment->forceFill(['status' => DeploymentStatus::Running])->save();
            $context->log->note('CI is done: the deploy goes ahead.');
        }

        $target = $context->target;
        $previous = $target->currentRelease()->first();
        $name = Release::nextName($target);
        $inReleases = $context->layout->versionable;

        $release = $target->releases()->create([
            'deployment_id' => $deployment->id,
            'name' => $name,
            'path' => $inReleases ? $context->layout->release($name) : $context->layout->root,
            'layout' => $inReleases ? Release::IN_RELEASES : Release::IN_PLACE,
            'commit_sha' => $deployment->commit_sha,
            'commit_message' => $deployment->commit_message,
            'strategy' => $resolution->strategy,
        ]);

        $deployment->forceFill(['release_id' => $release->id, 'previous_release_id' => $previous?->id])->save();

        $context->memo['release'] = $release;
        $context->memo['previous'] = $previous;
        $context->memo['strategy'] = $resolution->strategy;

        return $resolution->strategy->label().($release->shortSha() !== null ? ' · '.$release->shortSha() : '');
    }
}
