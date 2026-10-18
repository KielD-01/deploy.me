<?php

namespace App\Models;

use App\Deploy\Actions\ActionDefinition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A named job of a project's, run on one of its targets: from the
 * repository's .deployme/actions/<key>.yml (read-only here, edited in git)
 * or made on the page. A repo file that didn't pass the checks keeps its
 * errors and can't run until it does.
 */
#[Fillable(['project_id', 'source', 'key', 'file', 'name', 'definition', 'errors', 'commit_sha', 'loaded_at', 'scheduled_through'])]
class ProjectAction extends Model
{
    public const REPO = 'repo';

    public const APP = 'app';

    // Where the repository keeps them.
    public const FOLDER = '.deployme/actions';

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'errors' => 'array',
            'loaded_at' => 'datetime',
            'scheduled_through' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Deployment::class, 'action_id')->latest('id');
    }

    /**
     * @return HasOne<Deployment, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(Deployment::class, 'action_id')->latestOfMany();
    }

    public function fromRepo(): bool
    {
        return $this->source === self::REPO;
    }

    public function definition(): ?ActionDefinition
    {
        return $this->errors ? null : ActionDefinition::of($this->definition);
    }

    public function runnable(): bool
    {
        return $this->definition() !== null;
    }

    /**
     * Where it's from, as the list shows it.
     */
    public function origin(): string
    {
        return $this->fromRepo() ? (string) $this->file : 'Made here';
    }

    /**
     * A key for a name: "Database snapshot" → database-snapshot.
     */
    public static function keyFor(string $name): string
    {
        return Str::limit(Str::slug($name), 60, '') ?: 'action';
    }

    /**
     * A key no other action of the project has yet.
     */
    public static function uniqueKey(Project $project, string $name, ?int $except = null): string
    {
        $base = self::keyFor($name);
        $key = $base;

        for ($i = 2; self::query()->where('project_id', $project->id)->where('key', $key)->when($except, fn ($query) => $query->whereKeyNot($except))->exists(); $i++) {
            $key = "{$base}-{$i}";
        }

        return $key;
    }
}
