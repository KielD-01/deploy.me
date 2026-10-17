<?php

namespace App\Models;

use App\Deploy\RemoteLayout;
use App\Enums\DeploymentStatus;
use App\Enums\TargetHealth;
use App\Support\Drift;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A project assigned to a server. It deploys to `path`: {base folder}/{project
 * slug} by default (the server's deploy path, or another folder), or a folder
 * of its own whatever its name. The project's settings decide how.
 */
#[Fillable([
    'project_id', 'server_id', 'base_dir', 'path', 'health', 'bootstrapped_at', 'last_deployed_at', 'archived_at',
    'current_release_id', 'previous_release_id', 'health_checked_at', 'health_detail', 'unhealthy_since',
    'folder_scan', 'folder_scanned_at',
])]
class Target extends Model
{
    use HasFactory;

    protected $attributes = [
        'health' => 'unknown',
    ];

    protected function casts(): array
    {
        return [
            'health' => TargetHealth::class,
            'bootstrapped_at' => 'datetime',
            'last_deployed_at' => 'datetime',
            'archived_at' => 'datetime',
            'health_checked_at' => 'datetime',
            'unhealthy_since' => 'datetime',
            'folder_scan' => 'array',
            'folder_scanned_at' => 'datetime',
            'drift_behind' => 'integer',
            'drift_dirty' => 'integer',
            'drift_checked_at' => 'datetime',
        ];
    }

    /**
     * What the last RefreshDrift measured, as the dashboard shows it. The count
     * of commits behind was made for the commit that was live then: after a
     * deploy it is unknown (or none, when the live commit is the head) until
     * the next refresh, which is never more than a minute away.
     *
     * @return array{head: ?string, behind: ?int, dirty: ?int}
     */
    public function drift(): array
    {
        $live = $this->currentRelease?->commit_sha;

        // Not measured yet (a new target, or the first minute after an upgrade).
        if ($this->drift_checked_at === null) {
            return Drift::fromCi($this->project, $live);
        }

        $current = $this->drift_deployed === $live;

        return [
            'head' => $this->drift_head,
            'behind' => $current ? $this->drift_behind : ($this->drift_head !== null && $this->drift_head === $live ? 0 : null),
            'dirty' => $this->drift_dirty,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->latest('id');
    }

    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    public function activeDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->ofMany(['id' => 'max'], fn (Builder $query) => $query->whereIn('status', DeploymentStatus::ACTIVE));
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class)->orderByDesc('id');
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(TargetHealthCheck::class)->orderByDesc('checked_at')->orderByDesc('id');
    }

    public function currentRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'current_release_id');
    }

    public function previousRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'previous_release_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function layout(): RemoteLayout
    {
        return new RemoteLayout($this->path, $this->project->versionable);
    }

    /**
     * How many items an earlier deployment left in the folder, as last seen
     * (a read of the folder, or a pre-flight check).
     */
    public function leftoverCount(): int
    {
        $scan = $this->folder_scan ?? [];

        return isset($scan['items']) ? count($scan['items']) : (int) ($scan['foreign']['count'] ?? 0);
    }

    /**
     * Releases are how the project deploys, but the folder still runs from
     * an earlier deployment's files: it's converted before the first deploy.
     */
    public function needsConversion(): bool
    {
        return $this->project->versionable
            && ! ($this->currentRelease?->inReleasesFolder() ?? false)
            && $this->leftoverCount() > 0;
    }

    /**
     * Converting is on offer: releases, and nothing deployed into them yet.
     */
    public function canConvert(): bool
    {
        return $this->project->versionable && ! ($this->currentRelease?->inReleasesFolder() ?? false);
    }

    public function usesServerDefault(): bool
    {
        /** @var Server $server */
        $server = $this->server;

        return $server->default_deploy_path !== null
            && rtrim($this->path, '/') === self::pathFor($server->default_deploy_path, $this->slug());
    }

    private function slug(): string
    {
        /** @var Project $project */
        $project = $this->project;

        return $project->slug;
    }

    /**
     * Where the folder came from: "server default", "inside /srv/apps" or "exact folder".
     */
    public function folderNote(): string
    {
        return match (true) {
            $this->usesServerDefault() => 'server default',
            basename($this->path) === $this->slug() => 'inside '.dirname($this->path),
            default => 'exact folder',
        };
    }

    /**
     * Something deploy.me deployed or adopted runs from the folder: releases,
     * shared files and a stack that a new folder would leave behind. Such a
     * target's folder doesn't change.
     */
    public function isLive(): bool
    {
        return $this->current_release_id !== null;
    }

    public static function pathFor(string $baseDir, string $slug): string
    {
        return rtrim($baseDir, '/').'/'.$slug;
    }
}
