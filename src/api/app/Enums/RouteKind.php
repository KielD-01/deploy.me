<?php

namespace App\Enums;

/**
 * How deploy.me reaches a server. Direct leaves it to the user's ~/.ssh/config
 * (a ProxyJump there still applies); the others are written into the compiled
 * config ahead of it, so ssh, rsync and git all take the same way.
 */
enum RouteKind: string
{
    case Direct = 'direct';
    case Jump = 'jump';
    case Socks5 = 'socks5';
    case Http = 'http';
    case Vpn = 'vpn';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Jump => 'Jump host',
            self::Socks5 => 'SOCKS5 proxy',
            self::Http => 'HTTP proxy',
            self::Vpn => 'VPN on this machine',
        };
    }

    public function note(): string
    {
        return match ($this) {
            self::Direct => 'As your ~/.ssh/config says, ProxyJump included',
            self::Jump => 'ssh through another host first (ProxyJump)',
            self::Socks5 => 'ssh -D, Tailscale’s userspace proxy, an office proxy',
            self::Http => 'A proxy that allows CONNECT to the ssh port',
            self::Vpn => 'WireGuard or Tailscale on this machine; checked, not started',
        };
    }

    /**
     * The ssh connection runs through a proxy deploy.me speaks to itself.
     */
    public function isProxy(): bool
    {
        return $this === self::Socks5 || $this === self::Http;
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Socks5 => 1080,
            self::Http => 3128,
            default => null,
        };
    }
}
