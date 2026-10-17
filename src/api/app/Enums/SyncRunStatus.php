<?php

namespace App\Enums;

enum SyncRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    // Matches the partial unique index on sync_runs.folder_mapping_id.
    public const ACTIVE = ['queued', 'running'];

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Syncing',
            self::Succeeded => 'Done',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isActive(): bool
    {
        return in_array($this->value, self::ACTIVE, true);
    }
}
