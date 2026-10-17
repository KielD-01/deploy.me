<?php

namespace App\Platform\Remote;

use App\Platform\Ssh\Exceptions\RemoteCommandFailed;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;

final class ServerProber
{
    public function __construct(private readonly SshClient $ssh) {}

    /**
     * One round trip (reusing the connection) to measure latency.
     */
    public function ping(string $alias): int
    {
        $result = $this->ssh->run($alias, RemoteScript::inline('ping', 'true'), timeout: 20)->throw();

        return (int) round($result->seconds * 1000);
    }

    public function probe(string $alias): ProbeResult
    {
        $result = $this->ssh->run($alias, RemoteScript::file('probe', [
            // The network half of the busiest-process lists, which probe.sh runs as root.
            'NET_SAMPLER' => (string) file_get_contents(resource_path('ssh-scripts/probe-net.sh')),
        ], shell: 'sh'), timeout: 45)->throw();

        $json = $result->between('__DEPLOYME_PROBE_BEGIN__', '__DEPLOYME_PROBE_END__')
            ?? throw new RemoteCommandFailed('The probe finished without reporting anything.');

        return ProbeResult::fromJson($json);
    }
}
