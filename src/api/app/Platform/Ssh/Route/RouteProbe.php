<?php

namespace App\Platform\Ssh\Route;

use RuntimeException;

/**
 * The network side of a route check, so tests can stand in for it. Each
 * method throws a RuntimeException whose code is ProxyTunnel::PROXY_DOWN (the
 * way in failed) or ProxyTunnel::SERVER_DOWN (the server didn't answer
 * through it).
 */
interface RouteProbe
{
    /**
     * Opens and closes a TCP connection, as the VPN's check does.
     *
     * @throws RuntimeException
     */
    public function connect(string $host, int $port, float $timeout): void;

    /**
     * The first line host:port sends, reached directly or through the proxy.
     *
     * @throws RuntimeException
     */
    public function banner(Route $route, string $host, int $port, float $timeout): string;

    /**
     * The first line host:port sends, reached through a jump host (`ssh -W`).
     *
     * @throws RuntimeException
     */
    public function jump(string $jump, string $host, int $port, float $timeout): string;
}
