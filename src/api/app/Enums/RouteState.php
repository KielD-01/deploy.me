<?php

namespace App\Enums;

/**
 * What a route check found, in the order it looks: the way in (VPN, proxy or
 * jump host), then the server through it.
 */
enum RouteState: string
{
    case Up = 'up';
    case RouteDown = 'route_down';
    case ServerUnreachable = 'server_unreachable';

    public function label(): string
    {
        return match ($this) {
            self::Up => 'up',
            self::RouteDown => 'route down',
            self::ServerUnreachable => 'server unreachable',
        };
    }

    /**
     * "The route to prod-eu is down", for alerts, the probe and the deploy log.
     */
    public function headline(string $server): string
    {
        return match ($this) {
            self::Up => "The route to {$server} is up",
            self::RouteDown => "The route to {$server} is down",
            self::ServerUnreachable => "{$server} doesn’t answer through its route",
        };
    }

    public function ok(): bool
    {
        return $this === self::Up;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Up => 'badge-soft badge-success',
            self::RouteDown => 'badge-soft badge-error',
            self::ServerUnreachable => 'badge-soft badge-warning',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Up => 't-ok',
            self::RouteDown => 't-err',
            self::ServerUnreachable => 't-warn',
        };
    }
}
