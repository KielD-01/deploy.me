<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Enums\ReleaseStatus;
use App\Models\Release;
use App\Platform\Ssh\Exceptions\SshException;
use Illuminate\Support\Collection;

/**
 * Removes the images the releases that are gone were pinned to, so a server
 * doesn't keep every image the project ever shipped. Only what this project
 * pinned is touched (the tags and references in each release's
 * .deployme-pins.env, which WritePins wrote); the live release's images and
 * the rollback target's are always kept, and an image something still uses
 * is kept too. The release is live by now, so a problem here is reported,
 * not fatal.
 */
final class PruneImages implements Step
{
    public const KEY = 'prune_images';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Prune old images';
    }

    public function shouldRun(DeployContext $context): bool
    {
        $release = $context->memo['release'] ?? null;

        return Compose::builtin($context->project)
            && $release instanceof Release
            && ($release->image_pins ?? []) !== [];
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $live */
        $live = $context->memo['release'];
        $previous = $context->memo['previous'] ?? null;

        $pinned = $context->target->releases()->whereNotNull('image_pins')->reorder('name', 'desc')->get();
        $keep = self::keeping($context, $pinned, $live, $previous instanceof Release ? $previous : null);
        $remove = $pinned->reject(fn (Release $release) => $keep->contains('id', $release->id));

        $kinds = Compose::pinKinds($context->project);
        $going = self::pins($kinds, $remove);
        $staying = self::pins($kinds, $keep);
        $tags = array_values(array_diff($going['tags'], $staying['tags']));
        $refs = array_values(array_diff($going['refs'], $staying['refs']));

        if ($tags === [] && $refs === []) {
            return 'nothing to remove · '.$keep->count().' pinned';
        }

        try {
            $run = $context->script('deploy-prune-images', [
                ...Compose::vars($context->project, $live->path, 'prune'),
                'TAGS' => implode("\n", $tags),
                'KEEP_TAGS' => implode("\n", $staying['tags']),
                'REFS' => implode("\n", $refs),
                'KEEP_REFS' => implode("\n", $staying['refs']),
            ], timeout: 600)->throwIfFailed('Couldn’t remove the old images.');
        } catch (StepFailed|SshException $e) {
            $context->log->note('! '.($e instanceof SshException ? $e->userMessage() : $e->getMessage()));

            return 'couldn’t remove old images';
        }

        $removed = (int) $run->marker('removed');
        $inUse = (int) $run->marker('kept');

        return "removed {$removed}".($inUse > 0 ? " · {$inUse} still in use" : '');
    }

    /**
     * The releases whose images stay: the live one, the one a rollback would
     * switch back to, and the ones still on the server. An in-place project
     * has no release folders to count, so its pins are kept for as many
     * releases as the project keeps.
     *
     * @param  Collection<int, Release>  $pinned  newest first
     * @return Collection<int, Release>
     */
    private static function keeping(DeployContext $context, Collection $pinned, Release $live, ?Release $previous): Collection
    {
        $onServer = $pinned->filter(fn (Release $release) => $release->pruned_at === null && $release->status !== ReleaseStatus::Pruned);

        if (! $context->layout->versionable) {
            $onServer = $onServer->take(max(1, (int) $context->project->keep_releases));
        }

        return $onServer
            ->concat($pinned->filter(fn (Release $release) => in_array($release->id, array_filter([$live->id, $previous?->id]), true)))
            ->unique('id')
            ->values();
    }

    /**
     * What a set of releases pinned: the tags among their pins, and the whole
     * image references, told apart by what the compose file's variable holds.
     *
     * @param  array<string, string>  $kinds
     * @param  Collection<int, Release>  $releases
     * @return array{tags: list<string>, refs: list<string>}
     */
    private static function pins(array $kinds, Collection $releases): array
    {
        $tags = [];
        $refs = [];

        foreach ($releases as $release) {
            foreach ($release->image_pins ?? [] as $var => $value) {
                $value = (string) $value;

                if (($kinds[$var] ?? 'tag') === 'image') {
                    if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*:[A-Za-z0-9][A-Za-z0-9._-]*$~', $value) === 1) {
                        $refs[] = $value;
                    }
                } elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) === 1) {
                    $tags[] = $value;
                }
            }
        }

        return ['tags' => array_values(array_unique($tags)), 'refs' => array_values(array_unique($refs))];
    }
}
