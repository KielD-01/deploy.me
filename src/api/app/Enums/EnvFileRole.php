<?php

namespace App\Enums;

enum EnvFileRole: string
{
    // The .env next to the compose file: interpolation for docker compose.
    case Compose = 'compose';
    // What the application reads at runtime.
    case App = 'app';

    public function label(): string
    {
        return match ($this) {
            self::Compose => 'Compose root',
            self::App => 'App environment',
        };
    }
}
