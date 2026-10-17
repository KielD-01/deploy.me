<?php

namespace App\Enums;

enum EnvTag: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Environments are identities, not states, so they use the categorical
     * colours and never green/amber/red.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Production => 'badge-soft badge-secondary',
            self::Staging => 'badge-soft badge-accent',
            self::Development, self::Other => 'badge-ghost',
        };
    }
}
