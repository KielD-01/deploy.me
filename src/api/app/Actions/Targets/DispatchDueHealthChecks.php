<?php

namespace App\Actions\Targets;

use App\Enums\ServerStatus;
use App\Jobs\CheckHealth;
use App\Models\Target;
use App\Support\Settings;

/**
 * Queues a health check for every deployed target that has something to
 * check (a health URL, or a Docker stack deploy.me runs) and whose last check
 * is older than the interval in Settings → Schedules.
 */
class DispatchDueHealthChecks
{
    public function __invoke(): int
    {
        $interval = Settings::int('health_interval');

        if ($interval <= 0) {
            return 0;
        }

        $due = Target::query()
            ->active()
            ->whereNotNull('current_release_id')
            ->whereHas('server', fn ($query) => $query->whereNull('archived_at')->where('status', '!=', ServerStatus::Offline))
            ->whereHas('project', fn ($query) => $query->whereNull('archived_at')->where(fn ($query) => $query
                ->whereNotNull('health_url')
                ->orWhere(fn ($query) => $query->where('dockerized', true)->where('activation', 'builtin'))))
            ->where(fn ($query) => $query->whereNull('health_checked_at')->orWhere('health_checked_at', '<=', now()->subSeconds($interval)))
            ->pluck('id');

        foreach ($due as $id) {
            CheckHealth::dispatch($id);
        }

        return $due->count();
    }
}
