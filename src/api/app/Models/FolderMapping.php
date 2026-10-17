<?php

namespace App\Models;

use App\Enums\SyncDirection;
use App\Enums\SyncRunStatus;
use App\Support\Format;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A folder on the Mac paired with a folder on a server, synced one way.
 */
#[Fillable(['server_id', 'name', 'direction', 'local_path', 'remote_path', 'excludes', 'delete_extraneous', 'schedule_interval', 'next_run_at'])]
class FolderMapping extends Model
{
    use HasFactory;

    public const SCHEDULES = ['' => 'By hand', '3600' => 'Every hour', '86400' => 'Every day'];

    protected $attributes = [
        'delete_extraneous' => false,
    ];

    protected function casts(): array
    {
        return [
            'direction' => SyncDirection::class,
            'excludes' => 'array',
            'delete_extraneous' => 'boolean',
            'schedule_interval' => 'integer',
            'next_run_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class)->latest('id');
    }

    public function lastRealRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->ofMany(['id' => 'max'], fn (Builder $query) => $query->where('dry_run', false));
    }

    public function activeRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->ofMany(['id' => 'max'], fn (Builder $query) => $query->whereIn('status', SyncRunStatus::ACTIVE));
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->latestOfMany('id');
    }

    /**
     * The folders in the direction files move: "~/Claude/app/uploads → /opt/srv/app/uploads".
     */
    public function routeLabel(): string
    {
        $local = Format::homePath($this->local_path);

        return $this->direction === SyncDirection::Push ? "{$local} → {$this->remote_path}" : "{$this->remote_path} → {$local}";
    }

    public function scheduleLabel(): string
    {
        return self::SCHEDULES[(string) ($this->schedule_interval ?? '')] ?? 'Every '.intdiv((int) $this->schedule_interval, 60).' min';
    }
}
