<?php

namespace App\Enums;

enum ServerStatus: string
{
    case Online = 'online';
    case Degraded = 'degraded';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Degraded => 'Needs attention',
            self::Offline => 'Unreachable',
        };
    }

    /**
     * daisyUI colour name: status-{tone}, badge-{tone}.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Online => 'success',
            self::Degraded => 'warning',
            self::Offline => 'error',
        };
    }
}
