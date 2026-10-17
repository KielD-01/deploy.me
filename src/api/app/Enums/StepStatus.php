<?php

namespace App\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Running => 'loader',
            self::Succeeded => 'check-circle',
            self::Failed => 'x-circle',
            self::Skipped => 'skip',
            self::Cancelled => 'stop',
        };
    }

    public function textClass(): string
    {
        return match ($this) {
            self::Running => 'text-info',
            self::Succeeded => 'text-success',
            self::Failed => 'text-error',
            default => 'text-muted',
        };
    }

    public function isFinished(): bool
    {
        return ! in_array($this, [self::Pending, self::Running], true);
    }
}
