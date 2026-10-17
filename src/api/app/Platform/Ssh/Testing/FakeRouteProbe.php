<?php

namespace App\Platform\Ssh\Testing;

use App\Platform\Ssh\Route\ProxyTunnel;
use App\Platform\Ssh\Route\Route;
use App\Platform\Ssh\Route\RouteProbe;
use RuntimeException;

/**
 * A RouteProbe for tests: every hop answers unless told otherwise, and each
 * call is recorded.
 */
final class FakeRouteProbe implements RouteProbe
{
    /** @var list<string> */
    public array $calls = [];

    private ?RuntimeException $connectFails = null;

    private ?RuntimeException $serverFails = null;

    public function __construct(public string $banner = 'SSH-2.0-OpenSSH_9.6p1') {}

    /**
     * The VPN's check address, the proxy or the jump host doesn't answer.
     */
    public function routeDown(string $message = 'The SOCKS5 proxy at 10.0.0.2:1080 refused the connection.'): self
    {
        $this->connectFails = new RuntimeException($message, ProxyTunnel::PROXY_DOWN);

        return $this;
    }

    /**
     * The way in answers, the server behind it doesn't.
     */
    public function serverDown(string $message = 'The SOCKS5 proxy at 10.0.0.2:1080 couldn’t reach 10.20.0.5:22: connection refused.'): self
    {
        $this->serverFails = new RuntimeException($message, ProxyTunnel::SERVER_DOWN);

        return $this;
    }

    public function connect(string $host, int $port, float $timeout): void
    {
        $this->calls[] = "connect {$host}:{$port}";

        if ($this->connectFails !== null) {
            throw $this->connectFails;
        }
    }

    public function banner(Route $route, string $host, int $port, float $timeout): string
    {
        $this->calls[] = "banner {$route->kind->value} {$host}:{$port}";

        return $this->answer($route->kind->isProxy());
    }

    public function jump(string $jump, string $host, int $port, float $timeout): string
    {
        $this->calls[] = "jump {$jump} {$host}:{$port}";

        return $this->answer(true);
    }

    private function answer(bool $throughRoute): string
    {
        if ($throughRoute && $this->connectFails !== null) {
            throw $this->connectFails;
        }

        if ($this->serverFails !== null) {
            throw $this->serverFails;
        }

        return $this->banner;
    }
}
