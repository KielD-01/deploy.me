<?php

namespace App\Enums;

enum ReleaseStatus: string
{
    // Files on their way to the server.
    case Building = 'building';
    // On the server: live, or ready to roll back to.
    case Ready = 'ready';
    // Its deploy failed; the folder stays until the next prune.
    case Failed = 'failed';
    // Its folder was removed to keep the newest ones.
    case Pruned = 'pruned';

    public function label(): string
    {
        return match ($this) {
            self::Building => 'Uploading',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
            self::Pruned => 'Removed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Building => 'badge-soft badge-info',
            self::Ready => 'badge-ghost',
            self::Failed => 'badge-soft badge-error',
            self::Pruned => 'badge-ghost opacity-60',
        };
    }
}
