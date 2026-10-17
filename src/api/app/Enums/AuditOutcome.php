<?php

namespace App\Enums;

enum AuditOutcome: string
{
    case Ok = 'ok';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Failed => 'Failed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Ok => 'badge-soft badge-success',
            self::Failed => 'badge-soft badge-error',
        };
    }
}
