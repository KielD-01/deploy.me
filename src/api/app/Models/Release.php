<?php

namespace App\Models;

use App\Deploy\Compose;
use App\Enums\DeliveryStrategyKey;
use App\Enums\ReleaseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one deploy put on a server: a folder under releases/ (versionable
 * projects), or a record of what an in-place deploy wrote into the project
 * folder.
 */
#[Fillable([
    'target_id', 'deployment_id', 'name', 'path', 'layout', 'commit_sha', 'commit_message', 'strategy', 'status',
    'size_bytes', 'ran_migrations', 'migrations', 'backup', 'image_pins', 'adopted', 'color', 'activated_at', 'pruned_at',
])]
class Release extends Model
{
    use HasFactory;

    public const IN_RELEASES = 'releases';

    public const IN_PLACE = 'in_place';

    protected $attributes = [
        'status' => 'building',
        'ran_migrations' => false,
        'adopted' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => ReleaseStatus::class,
            'strategy' => DeliveryStrategyKey::class,
            'size_bytes' => 'integer',
            'ran_migrations' => 'boolean',
            'migrations' => 'integer',
            'image_pins' => 'array',
            'adopted' => 'boolean',
            'activated_at' => 'datetime',
            'pruned_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function shortSha(): ?string
    {
        return $this->commit_sha !== null ? substr($this->commit_sha, 0, 7) : null;
    }

    public function inReleasesFolder(): bool
    {
        return $this->layout === self::IN_RELEASES;
    }

    /**
     * Still on the server, whole, in its own folder: something to switch to.
     */
    public function canSwitchTo(): bool
    {
        return $this->inReleasesFolder() && $this->status === ReleaseStatus::Ready && $this->pruned_at === null;
    }

    /**
     * Deployed in place, and it recorded which images it ran: putting its pins
     * back and bringing the stack up again is how it goes live once more. The
     * files in the folder stay as they are — for a project whose code is in
     * the image, they are only the compose file and its env.
     */
    public function canPinBack(): bool
    {
        return ! $this->inReleasesFolder()
            && $this->status === ReleaseStatus::Ready
            && $this->pruned_at === null
            && ($this->image_pins ?? []) !== [];
    }

    /**
     * Something a rollback can make live again on this project's target.
     */
    public function canRollBackTo(Project $project): bool
    {
        return $project->versionable ? $this->canSwitchTo() : (Compose::builtin($project) && $this->canPinBack());
    }

    /**
     * The folder name for a new release: the time in UTC (20260910-143012),
     * with a suffix when one already exists from the same second.
     */
    public static function nextName(Target $target): string
    {
        $base = now()->utc()->format('Ymd-His');
        $name = $base;

        for ($i = 2; $target->releases()->where('name', $name)->exists(); $i++) {
            $name = "{$base}-{$i}";
        }

        return $name;
    }

    public static function isName(string $name): bool
    {
        return preg_match('/^\d{8}-\d{6}(-\d+)?$/', $name) === 1;
    }
}
