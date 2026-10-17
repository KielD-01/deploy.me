<?php

namespace App\Actions\Servers;

use App\Enums\ServerStatus;
use App\Events\ServerProbed;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Platform\Remote\ProbeResult;
use App\Platform\Remote\ServerProber;
use App\Platform\Ssh\Exceptions\HostUnreachable;
use App\Platform\Ssh\Exceptions\SshException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Probes a server and records the result: a snapshot, the server's specs and
 * capabilities, and its status. Used by the scheduled job, "Probe now", and
 * the add-server wizard (which already has a probe result in hand).
 */
class RecordProbe
{
    public function __construct(private readonly ServerProber $prober) {}

    /**
     * @param  bool  $retry  after a dropped connection, try once more before
     *                       calling the server offline (the scheduled probe does)
     */
    public function __invoke(Server $server, bool $retry = false): ?ServerSnapshot
    {
        try {
            return $this->attempt($server);
        } catch (HostUnreachable $e) {
            if (! $retry) {
                return $this->fail($server, $e);
            }
        } catch (SshException $e) {
            return $this->fail($server, $e);
        }

        // One lost connection on the way shouldn't flip a server to offline.
        Sleep::for(5)->seconds();

        try {
            return $this->attempt($server);
        } catch (SshException $e) {
            return $this->fail($server, $e);
        }
    }

    private function attempt(Server $server): ServerSnapshot
    {
        $latency = $this->prober->ping($server->ssh_alias);
        $probe = $this->prober->probe($server->ssh_alias);

        return $this->store($server, $probe, $latency);
    }

    private function fail(Server $server, SshException $e): null
    {
        $server->forceFill([
            'status' => ServerStatus::Offline,
            'status_reason' => $e->userMessage(),
            'last_error' => $e->getMessage(),
            'last_probe_at' => now(),
            // Look again within a minute, so a short outage clears quickly.
            'next_probe_at' => $this->nextProbeAt(min(60, $server->probe_interval)),
        ])->save();

        $this->announce($server);

        return null;
    }

    public function store(Server $server, ProbeResult $probe, ?int $latencyMs = null): ServerSnapshot
    {
        $snapshot = $server->snapshots()->create([
            ...$probe->snapshot(),
            'captured_at' => now(),
            'ssh_latency_ms' => $latencyMs,
        ]);

        $disk = $probe->diskFor($server->default_deploy_path ?: '/');
        $diskUsed = ProbeResult::usedPercent($disk);
        $degraded = $diskUsed !== null && $diskUsed >= config('deployme.probe.disk_warning_pct');

        $server->forceFill([
            'specs' => $probe->specs(),
            'capabilities' => $probe->capabilities(),
            'status' => $degraded ? ServerStatus::Degraded : ServerStatus::Online,
            'status_reason' => $degraded ? "Disk {$diskUsed}% full on {$disk['mount']}" : null,
            'last_error' => null,
            'last_probe_at' => now(),
            'last_seen_at' => now(),
            'next_probe_at' => $this->nextProbeAt($server->probe_interval),
        ])->save();

        $this->announce($server);

        return $snapshot;
    }

    /**
     * The scheduler looks for due probes once a minute and then waits for the
     * middle of it (DispatchDueProbes), so the next probe is due at the tick that
     * should start it. Counting from the end of this probe instead would miss
     * that tick by seconds and stretch every interval by a minute.
     */
    private function nextProbeAt(int $seconds): CarbonInterface
    {
        return now()->addSeconds($seconds)->startOfMinute();
    }

    private function announce(Server $server): void
    {
        try {
            ServerProbed::dispatch($server->id, $server->status->value);
        } catch (Throwable $e) {
            // Live updates are a convenience; a Reverb outage must not fail the probe.
            Log::warning('Could not broadcast ServerProbed', ['server' => $server->id, 'error' => $e->getMessage()]);
        }
    }
}
