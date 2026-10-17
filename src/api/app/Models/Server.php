<?php

namespace App\Models;

use App\Enums\EnvTag;
use App\Enums\RouteKind;
use App\Enums\RouteState;
use App\Enums\ServerStatus;
use App\Platform\Ssh\Route\Route;
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
    'route_kind', 'route', 'route_secret', 'route_state', 'route_detail', 'route_checked_at',
])]
class Server extends Model
{
    use HasFactory;

    protected $hidden = ['route_secret'];

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
            'route_kind' => RouteKind::class,
            'route' => 'array',
            'route_secret' => 'encrypted',
            'route_state' => RouteState::class,
            'route_checked_at' => 'datetime',
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

    public function routeChecks(): HasMany
    {
        return $this->hasMany(RouteCheck::class)->orderByDesc('checked_at')->orderByDesc('id');
    }

    /**
     * How deploy.me reaches the server: direct, or through the route set on
     * the server page.
     */
    public function connectionRoute(): Route
    {
        return Route::fromServer($this);
    }

    public function hasRoute(): bool
    {
        return ($this->route_kind ?? RouteKind::Direct) !== RouteKind::Direct;
    }

    /**
     * The last route check found it down: probes, deploys and health checks
     * wait for it rather than each failing on its own.
     */
    public function routeIsDown(): bool
    {
        return $this->hasRoute() && $this->route_state !== null && ! $this->route_state->ok();
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
