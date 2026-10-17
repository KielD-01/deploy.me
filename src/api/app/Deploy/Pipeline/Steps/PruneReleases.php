<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\StepTimeout;
use App\Deploy\ReleasePruner;
use App\Enums\ReleaseStatus;
use App\Models\Release;
use App\Platform\Ssh\Exceptions\SshException;
use Illuminate\Support\Str;

/**
 * Removes release folders beyond the project's keep count. The release is
 * live and healthy by now, so a problem here is reported, not fatal.
 */
final class PruneReleases implements Step
{
    public const KEY = 'prune';

    public function __construct(private readonly int $keep = 5) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return "Prune to {$this->keep} ".Str::plural('release', $this->keep);
    }

    public function shouldRun(DeployContext $context): bool
    {
        return $context->layout->versionable && $context->release instanceof Release;
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $live */
        $live = $context->release;
        $previous = $context->previous;

        $onDisk = $context->target->releases()
            ->where('layout', Release::IN_RELEASES)
            ->whereIn('status', [ReleaseStatus::Ready, ReleaseStatus::Failed])
            ->whereNull('pruned_at')
            ->whereKeyNot($live->id)
            ->reorder('name', 'desc')
            ->get();

        $remove = ReleasePruner::select($onDisk, $live, $previous instanceof Release ? $previous : null, $context->project->keep_releases);
        $kept = $onDisk->count() - count($remove) + 1;

        if ($remove === []) {
            return "{$kept} kept";
        }

        try {
            $context->script('deploy-prune', [
                'TARGET' => $context->layout->root,
                'NAMES' => implode("\n", array_map(fn (Release $release) => $release->name, $remove)),
                'KEEP' => $live->name,
            ], timeout: StepTimeout::PRUNE)->throwIfFailed('Couldn’t remove the old releases.');
        } catch (StepFailed|SshException $e) {
            $context->log->note('! '.($e instanceof SshException ? $e->userMessage() : $e->getMessage()));

            return 'couldn’t remove old releases';
        }

        Release::query()->whereKey(array_map(fn (Release $release) => $release->id, $remove))->update(['status' => ReleaseStatus::Pruned, 'pruned_at' => now()]);

        return 'removed '.count($remove)." · {$kept} kept";
    }
}
