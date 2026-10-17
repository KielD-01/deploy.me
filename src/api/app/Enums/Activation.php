<?php

namespace App\Enums;

enum Activation: string
{
    // deploy.me's own steps: upload, build, switch, health check.
    case Builtin = 'builtin';
    // The repository's deploy script does the switch; deploy.me keeps the
    // lock, the health gate and the release record.
    case Script = 'script';

    public function label(): string
    {
        return match ($this) {
            self::Builtin => 'Built-in steps',
            self::Script => 'Project script',
        };
    }
}
