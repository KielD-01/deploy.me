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
 * Servers start 5 seconds apart over the next 25 (their id modulo 6), not all
 * on the same second, so the probes worker isn't handed every one at once.
 */
class DispatchDueProbes
{
    public function __invoke(): int
    {
        $ids = Server::query()
            ->active()
            ->where(fn ($query) => $query->whereNull('next_probe_at')->orWhere('next_probe_at', '<=', now()))
            ->pluck('id');

        $minute = now()->startOfMinute();

        $ids->each(fn (int $id) => ProbeServer::dispatch($id)->delay($minute->copy()->addSeconds(self::second($id))));

        return $ids->count();
    }

    /**
     * The second of the minute a server's probe starts at: the configured one
     * plus a step per remainder of its id, and never the minute's own boundary.
     */
    public static function second(int $serverId): int
    {
        $spread = max(1, (int) config('deployme.probe.spread'));
        $second = (int) config('deployme.probe.second') + $serverId % $spread * (int) config('deployme.probe.spread_step');

        return max(1, min(59, $second));
    }
}
