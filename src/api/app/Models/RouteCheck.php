<?php

namespace App\Models;

use App\Enums\RouteKind;
use App\Enums\RouteState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check of the way to a server: the VPN, proxy or jump host, then the
 * server through it. The last result also lives on the server.
 */
#[Fillable(['server_id', 'kind', 'state', 'reason', 'deployment_id', 'detail', 'duration_ms', 'checked_at'])]
class RouteCheck extends Model
{
    public $timestamps = false;

    public const REASONS = [
        'manual' => 'checked by hand',
        'probe' => 'before a probe',
        'deploy' => 'before a deploy',
        'doctor' => 'deployme:doctor',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RouteKind::class,
            'state' => RouteState::class,
            'duration_ms' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
