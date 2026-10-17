<?php

namespace App\Platform\Ssh\Route;

use App\Enums\RouteKind;
use App\Models\Server;

/**
 * The way to a server: nothing (direct), a jump host, a SOCKS5 or HTTP proxy,
 * or a VPN that runs on this machine. The proxy password is the only secret;
 * it never reaches argv, a log or a page.
 */
final readonly class Route
{
    public function __construct(
        public RouteKind $kind = RouteKind::Direct,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $user = null,
        #[\SensitiveParameter]
        public ?string $password = null,
        public ?string $jump = null,
        public ?string $checkHost = null,
        public ?int $checkPort = null,
    ) {}

    public static function fromServer(Server $server): self
    {
        $route = $server->route ?? [];

        return new self(
            kind: $server->route_kind ?? RouteKind::Direct,
            host: $route['host'] ?? null,
            port: isset($route['port']) ? (int) $route['port'] : null,
            user: ($route['user'] ?? null) ?: null,
            password: $server->route_secret ?: null,
            jump: $route['jump'] ?? null,
            checkHost: ($route['check_host'] ?? null) ?: null,
            checkPort: isset($route['check_port']) ? (int) $route['check_port'] : null,
        );
    }

    /**
     * What goes in `servers.route`: everything but the password.
     *
     * @return array<string, mixed>|null
     */
    public function settings(): ?array
    {
        return match ($this->kind) {
            RouteKind::Direct => null,
            RouteKind::Jump => ['jump' => $this->jump],
            RouteKind::Socks5, RouteKind::Http => array_filter(['host' => $this->host, 'port' => $this->port, 'user' => $this->user], fn ($value) => $value !== null),
            RouteKind::Vpn => array_filter(['check_host' => $this->checkHost, 'check_port' => $this->checkPort], fn ($value) => $value !== null),
        };
    }

    /**
     * Why it can't be used, by form field, or null when it can.
     *
     * @return array{0: string, 1: string}|null
     */
    public function problem(string $serverAlias): ?array
    {
        return match ($this->kind) {
            RouteKind::Direct => null,
            RouteKind::Jump => match (true) {
                ! self::isJump((string) $this->jump) => ['routeJump', 'Use an alias from your ~/.ssh/config, or user@host:port.'],
                $this->jump === $serverAlias => ['routeJump', 'A server can’t be its own jump host.'],
                default => null,
            },
            RouteKind::Socks5, RouteKind::Http => match (true) {
                ! self::isHost((string) $this->host) => ['routeHost', 'Use a host name or an IP address, like 10.0.0.2 or host.docker.internal.'],
                ! self::isPort($this->port) => ['routePort', 'Use a port from 1 to 65535.'],
                $this->user !== null && ! self::isCredential($this->user) => ['routeUser', 'Up to 255 characters, no control characters.'],
                $this->password !== null && ! self::isCredential($this->password) => ['routePassword', 'Up to 255 characters, no control characters.'],
                $this->password !== null && $this->user === null => ['routeUser', 'A password needs the user it belongs to.'],
                default => null,
            },
            RouteKind::Vpn => match (true) {
                $this->checkHost === null && $this->checkPort === null => null,
                ! self::isHost((string) $this->checkHost) => ['routeCheckHost', 'Use a host name or an IP address that only the VPN reaches, like 10.8.0.1.'],
                ! self::isPort($this->checkPort) => ['routeCheckPort', 'Use a port from 1 to 65535.'],
                default => null,
            },
        };
    }

    /**
     * "socks5 10.0.0.2:1080", "jump bastion", "vpn · checked at 10.8.0.1:53".
     */
    public function label(): string
    {
        return match ($this->kind) {
            RouteKind::Direct => 'direct',
            RouteKind::Jump => "jump host {$this->jump}",
            RouteKind::Socks5 => "SOCKS5 {$this->host}:{$this->port}",
            RouteKind::Http => "HTTP proxy {$this->host}:{$this->port}",
            RouteKind::Vpn => $this->checkHost !== null ? "VPN, checked at {$this->checkHost}:{$this->checkPort}" : 'VPN on this machine',
        };
    }

    /**
     * What the ProxyCommand helper reads from its 0600 file.
     *
     * @return array<string, mixed>
     */
    public function proxyFile(int $timeout): array
    {
        return [
            'kind' => $this->kind->value,
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'password' => $this->password,
            'timeout' => $timeout,
        ];
    }

    public static function isHost(string $host): bool
    {
        return strlen($host) <= 253 && (
            preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,62})(?:\.[A-Za-z0-9-]{1,63})*$/', $host) === 1
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
        );
    }

    public static function isPort(?int $port): bool
    {
        return $port !== null && $port >= 1 && $port <= 65535;
    }

    /**
     * An alias, or [user@]host[:port], one hop. Nothing ssh would read as an
     * option or a second hop.
     */
    public static function isJump(string $jump): bool
    {
        return strlen($jump) <= 255 && preg_match('/^(?:[A-Za-z0-9._-]+@)?[A-Za-z0-9_][A-Za-z0-9._-]*(?::\d{1,5})?$/', $jump) === 1;
    }

    private static function isCredential(string $value): bool
    {
        return $value !== '' && strlen($value) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
