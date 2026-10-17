<?php

namespace App\Deploy;

use App\Enums\ReleaseStatus;
use App\Models\Release;
use Illuminate\Support\Collection;

/**
 * Which release folders go once a deploy is live: the newest `keep` stay,
 * counting the live one, and the one it replaced stays too while there's
 * room for it (it's what a rollback switches to). Failed ones always go.
 */
final class ReleasePruner
{
    /**
     * @param  Collection<int, Release>  $onDisk  newest first
     * @return list<Release>
     */
    public static function select(Collection $onDisk, Release $live, ?Release $previous, int $keep): array
    {
        $keep = max(1, $keep);
        $kept = [$live->id];

        if ($previous !== null && $keep >= 2 && $previous->id !== $live->id) {
            $kept[] = $previous->id;
        }

        foreach ($onDisk as $release) {
            if (count($kept) >= $keep) {
                break;
            }

            if ($release->status === ReleaseStatus::Ready && ! in_array($release->id, $kept, true)) {
                $kept[] = $release->id;
            }
        }

        return $onDisk->reject(fn (Release $release) => in_array($release->id, $kept, true))->values()->all();
    }
}
