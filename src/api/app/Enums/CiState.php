<?php

namespace App\Enums;

/**
 * GitHub Actions on a project's deploy branch, from its latest runs.
 */
enum CiState: string
{
    case Passing = 'passing';
    case Failing = 'failing';
    case Running = 'running';
    case NoWorkflows = 'no_workflows';
    case NoRemote = 'no_remote';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Passing => 'Passing',
            self::Failing => 'Failing',
            self::Running => 'In progress',
            self::NoWorkflows => 'No workflows',
            self::NoRemote => 'No remote',
            self::Unknown => 'Unknown',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Passing => 'badge-soft badge-success',
            self::Failing => 'badge-soft badge-error',
            self::Running => 'badge-soft badge-info',
            default => 'badge-ghost',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Passing => 'check-circle',
            self::Failing => 'x-circle',
            self::Running => 'loader',
            default => 'ci',
        };
    }

    public function hasSignal(): bool
    {
        return in_array($this, [self::Passing, self::Failing, self::Running], true);
    }
}
