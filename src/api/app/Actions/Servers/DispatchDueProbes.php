<?php

namespace App\Actions\Servers;

use App\Jobs\ProbeServer;
use App\Models\Server;

/**
 * Runs every minute from the scheduler: queues a probe for each server whose
 * interval has elapsed. ProbeServer is unique per server, so a slow probe is
 * never queued twice.
 *
 * The probe waits for the middle of the minute. Cron jobs and Laravel
 * schedulers on the servers start on the minute, most of them on every fifth,
 * and a burst of PHP boots can keep every core busy for a few seconds: a CPU
 * sample taken then reads 100% on a machine that is idle the rest of the time.
 */
class DispatchDueProbes
{
    public function __invoke(): int
    {
        $ids = Server::query()
            ->active()
            ->where(fn ($query) => $query->whereNull('next_probe_at')->orWhere('next_probe_at', '<=', now()))
            ->pluck('id');

        $at = now()->startOfMinute()->addSeconds(config('deployme.probe.second'));

        $ids->each(fn (int $id) => ProbeServer::dispatch($id)->delay($at));

        return $ids->count();
    }
}
