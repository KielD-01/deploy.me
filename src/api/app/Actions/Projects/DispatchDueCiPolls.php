<?php

namespace App\Actions\Projects;

use App\Jobs\PollCiStatus;
use App\Models\Project;
use App\Support\Settings;

/**
 * Queues a CI poll for every GitHub project whose status is older than the
 * poll interval.
 */
class DispatchDueCiPolls
{
    public function __invoke(): int
    {
        $interval = Settings::int('ci_poll_interval');

        $due = Project::query()
            ->active()
            ->whereNotNull('github_owner')
            ->where(fn ($query) => $query->whereNull('ci_checked_at')->orWhere('ci_checked_at', '<=', now()->subSeconds($interval)))
            ->pluck('id');

        foreach ($due as $id) {
            PollCiStatus::dispatch($id);
        }

        return $due->count();
    }
}
