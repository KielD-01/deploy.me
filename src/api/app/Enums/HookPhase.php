<?php

namespace App\Enums;

enum HookPhase: string
{
    case AfterUpload = 'after_upload';
    case Release = 'release';
    case AfterActivate = 'after_activate';

    public function label(): string
    {
        return match ($this) {
            self::AfterUpload => 'After upload',
            self::Release => 'Release',
            self::AfterActivate => 'After activate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AfterUpload => 'Runs in the new release folder before anything is built.',
            self::Release => 'Runs before the switch. A failure stops the deploy and nothing goes live.',
            self::AfterActivate => 'Runs once the new release is live and healthy.',
        };
    }
}
