<?php

namespace App\Enums;

enum TargetHealth: string
{
    case Unknown = 'unknown';
    case Healthy = 'healthy';
    case Unhealthy = 'unhealthy';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not checked',
            self::Healthy => 'Healthy',
            self::Unhealthy => 'Unhealthy',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Healthy => 'badge-soft badge-success',
            self::Unhealthy => 'badge-soft badge-error',
            self::Unknown => 'badge-ghost',
        };
    }
}
