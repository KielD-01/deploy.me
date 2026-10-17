<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    case WaitingCi = 'waiting_ci';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Cancelled = 'cancelled';
    // The worker stopped reporting while it ran.
    case Stalled = 'stalled';

    // Matches the partial unique index on deployments.target_id.
    public const ACTIVE = ['queued', 'waiting_ci', 'running'];

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::WaitingCi => 'Waiting for CI',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::RolledBack => 'Rolled back',
            self::Cancelled => 'Cancelled',
            self::Stalled => 'Stalled',
        };
    }

    public function isActive(): bool
    {
        return in_array($this->value, self::ACTIVE, true);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Running, self::WaitingCi => 'badge-soft badge-info',
            self::Succeeded => 'badge-soft badge-success',
            self::Failed, self::Stalled => 'badge-soft badge-error',
            self::RolledBack => 'badge-soft badge-warning',
            self::Queued, self::Cancelled => 'badge-ghost',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Queued => 'pending',
            self::WaitingCi => 'clock',
            self::Running => 'loader',
            self::Succeeded => 'check-circle',
            self::Failed, self::Stalled => 'x-circle',
            self::RolledBack => 'rollback',
            self::Cancelled => 'stop',
        };
    }
}
