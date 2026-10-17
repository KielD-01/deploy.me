<?php

namespace App\Jobs;

use App\Models\Target;
use App\Support\Drift;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Measures how far each live target is behind its branch (`Drift`: three git
 * processes per local repository) and keeps the answer on the target, so the
 * dashboard reads columns instead of running git on every render. The
 * scheduler queues it every minute; a CI poll that found something new queues
 * it for that project.
 */
class RefreshDrift implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(public ?int $projectId = null)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) ($this->projectId ?? 'all');
    }

    public function handle(): void
    {
        $targets = Target::query()
            ->active()
            ->when($this->projectId !== null, fn ($query) => $query->where('project_id', $this->projectId))
            ->whereHas('project', fn ($query) => $query->whereNull('archived_at'))
            ->whereHas('server', fn ($query) => $query->whereNull('archived_at'))
            ->with(['project', 'currentRelease'])
            ->get();

        // Two targets of a project on the same commit ask git the same thing.
        $measured = [];

        foreach ($targets as $target) {
            $deployed = $target->currentRelease?->commit_sha;
            $key = $target->project_id.'|'.($deployed ?? '-');

            // One repository that won't answer mustn't leave the others unmeasured.
            $measured[$key] ??= rescue(fn () => Drift::measure($target->project, $deployed), null);

            if ($measured[$key] === null) {
                continue;
            }

            // Straight to the table: a measurement isn't a change to the target.
            Target::query()->whereKey($target->id)->toBase()->update([
                'drift_head' => $measured[$key]['head'],
                'drift_behind' => $measured[$key]['behind'],
                'drift_dirty' => $measured[$key]['dirty'],
                'drift_deployed' => $deployed,
                'drift_checked_at' => now(),
            ]);
        }
    }
}
