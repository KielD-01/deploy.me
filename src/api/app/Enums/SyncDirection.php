<?php

namespace App\Enums;

enum SyncDirection: string
{
    // Mac → server.
    case Push = 'push';
    // Server → Mac.
    case Pull = 'pull';

    public function label(): string
    {
        return match ($this) {
            self::Push => 'Push',
            self::Pull => 'Pull',
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::Push => 'Mac → server',
            self::Pull => 'server → Mac',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Push => 'arrow-up',
            self::Pull => 'arrow-down',
        };
    }
}
