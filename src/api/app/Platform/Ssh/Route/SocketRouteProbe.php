<?php

namespace App\Platform\Ssh\Route;

use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\SshConfigCompiler;
use App\Platform\Ssh\SshErrors;
use App\Platform\Ssh\SshResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Route checks over real sockets, from inside the container: the same place
 * ssh connects from.
 */
final class SocketRouteProbe implements RouteProbe
{
    public function __construct(
        private readonly SshConfigCompiler $compiler,
        private readonly KnownHosts $knownHosts,
    ) {}

    public function connect(string $host, int $port, float $timeout): void
    {
        fclose(ProxyTunnel::connect($host, $port, $timeout, "{$host}:{$port}", ProxyTunnel::PROXY_DOWN));
    }

    public function banner(Route $route, string $host, int $port, float $timeout): string
    {
        $socket = $route->kind->isProxy()
            ? ProxyTunnel::open($route->kind->value, (string) $route->host, (int) $route->port, $route->user, $route->password, $host, $port, $timeout)
            : ProxyTunnel::connect($host, $port, $timeout, "{$host}:{$port}", ProxyTunnel::SERVER_DOWN);

        try {
            $line = ProxyTunnel::banner($socket, $timeout);
        } finally {
            fclose($socket);
        }

        if ($line === null || $line === '') {
            throw new RuntimeException("{$host}:{$port} accepted the connection but said nothing.", ProxyTunnel::SERVER_DOWN);
        }

        return $line;
    }

    public function jump(string $jump, string $host, int $port, float $timeout): string
    {
        $config = $this->compiler->compile();
        $this->knownHosts->materialize();

        $result = Process::timeout((int) ceil($timeout) * 3)->input('')->run([
            config('deployme.ssh.binary'), '-F', $config,
            '-o', 'ControlMaster=no', '-o', 'ControlPath=none',
            '-W', "{$host}:{$port}", $jump,
        ]);

        $line = trim(strtok($result->output(), "\n") ?: '');

        if (str_starts_with($line, 'SSH-')) {
            return $line;
        }

        $stderr = $result->errorOutput();

        // The jump host is in, but it couldn't open the way on to the server.
        if (preg_match('/open failed|stdio forwarding failed|administratively prohibited/i', $stderr) === 1) {
            throw new RuntimeException("The jump host {$jump} couldn’t reach {$host}:{$port}: ".trim(preg_replace('/\s+/', ' ', $stderr)), ProxyTunnel::SERVER_DOWN);
        }

        throw new RuntimeException("The jump host {$jump} let deploy.me down: ".SshErrors::from(new SshResult(255, '', $stderr ?: 'no answer', 0))->userMessage(), ProxyTunnel::PROXY_DOWN);
    }
}
