<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the audit log. The table refuses UPDATE and DELETE (except the
 * nightly prune), so nothing here is ever edited: no updated_at, no saves.
 */
#[Fillable(['occurred_at', 'user_id', 'actor', 'action', 'subject', 'server_id', 'project_id', 'outcome', 'detail'])]
class AuditEntry extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'action' => AuditAction::class,
            'outcome' => AuditOutcome::class,
        ];
    }

    public function scopeForServer(Builder $query, ?int $serverId): void
    {
        $query->when($serverId !== null, fn (Builder $query) => $query->where('server_id', $serverId));
    }

    public function scopeForProject(Builder $query, ?int $projectId): void
    {
        $query->when($projectId !== null, fn (Builder $query) => $query->where('project_id', $projectId));
    }

    /**
     * The deliberate acts, without the commands deploys and probes run
     * (AuditAction::RemoteCommand, 'ssh.command').
     */
    public function scopeByHand(Builder $query): void
    {
        // A literal, not a binding: the partial index on `action <> 'ssh.command'`
        // is only used when the planner can see that this is the same condition.
        $query->whereRaw("action <> 'ssh.command'");
    }
}
