<?php

namespace App\Models;

use App\Enums\EnvTag;
use App\Enums\ServerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'slug', 'ssh_alias', 'host_name', 'ssh_user', 'port', 'identity_files', 'proxy_jump',
    'env_tag', 'status', 'status_reason', 'default_deploy_path', 'reserved_paths', 'specs', 'capabilities',
    'probe_interval', 'last_probe_at', 'next_probe_at', 'last_seen_at', 'last_error', 'archived_at',
])]
class Server extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'identity_files' => 'array',
            'reserved_paths' => 'array',
            'specs' => 'array',
            'capabilities' => 'array',
            'env_tag' => EnvTag::class,
            'status' => ServerStatus::class,
            'probe_interval' => 'integer',
            'last_probe_at' => 'datetime',
            'next_probe_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ServerSnapshot::class);
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(ServerSnapshot::class)->latestOfMany('captured_at');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(Target::class);
    }

    public function folderMappings(): HasMany
    {
        return $this->hasMany(FolderMapping::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * user@host, plus the port when it isn't 22.
     */
    public function connectionLabel(): string
    {
        return ($this->ssh_user ? $this->ssh_user.'@' : '').($this->host_name ?? $this->ssh_alias).($this->port !== 22 ? ':'.$this->port : '');
    }

    /**
     * A path is reserved if it is, or is inside, one of this server's reserved
     * paths — however it's spelled: /opt/./infra and //opt/infra are /opt/infra.
     */
    public function isReserved(string $path): bool
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            match ($part) {
                '', '.' => null,
                '..' => array_pop($parts),
                default => $parts[] = $part,
            };
        }
        $path = '/'.implode('/', $parts);

        foreach ([...config('deployme.reserved_paths', []), ...($this->reserved_paths ?? [])] as $reserved) {
            if ($path === $reserved || str_starts_with(rtrim($path, '/').'/', rtrim($reserved, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'server';
        $slug = $base;

        for ($i = 2; static::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
