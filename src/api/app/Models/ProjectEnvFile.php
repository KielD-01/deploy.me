<?php

namespace App\Models;

use App\Enums\EnvFileRole;
use App\Support\DotEnv;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * An env file a project ships to its targets, encrypted at rest. Every save
 * becomes a new version; the previous one is kept.
 */
#[Fillable(['path', 'role', 'content', 'content_hash', 'key_count', 'version'])]
class ProjectEnvFile extends Model
{
    protected $hidden = ['content'];

    protected function casts(): array
    {
        return [
            'role' => EnvFileRole::class,
            'content' => 'encrypted',
            'key_count' => 'integer',
            'version' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProjectEnvVersion::class)->orderByDesc('version');
    }

    /**
     * Keyed with APP_KEY, so the hash says nothing about the content to
     * anyone who only has the database.
     */
    public static function hash(string $content): string
    {
        return hash_hmac('sha256', $content, (string) config('app.key'));
    }

    /**
     * @return array{content: string, content_hash: string, key_count: int}
     */
    public static function contentAttributes(string $content): array
    {
        return ['content' => $content, 'content_hash' => self::hash($content), 'key_count' => count(DotEnv::parse($content))];
    }

    /**
     * Saves new content as the next version. False when nothing changed.
     */
    public function revise(string $content): bool
    {
        if (hash_equals($this->content_hash, self::hash($content))) {
            return false;
        }

        DB::transaction(function () use ($content) {
            $this->versions()->create([
                'version' => $this->version,
                'content' => $this->content,
                'content_hash' => $this->content_hash,
                'created_at' => $this->updated_at ?? now(),
            ]);

            $this->fill(self::contentAttributes($content) + ['version' => $this->version + 1])->save();
        });

        return true;
    }
}
