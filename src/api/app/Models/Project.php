<?php

namespace App\Models;

use App\Deploy\Delivery\ImagePlan;
use App\Enums\Activation;
use App\Enums\CiState;
use App\Enums\DeliveryStrategyKey;
use App\Enums\ProjectSource;
use App\Platform\Detection\Detection;
use App\Support\Format;
use App\Support\Names;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name', 'slug', 'source', 'local_path', 'git_remote', 'github_owner', 'github_repo', 'github_repo_id', 'credential_id',
    'branch', 'framework', 'versionable', 'keep_releases', 'dockerized', 'stack_name', 'compose_files', 'app_services',
    'migrate_service', 'delivery_strategies', 'wait_for_ci', 'health_url', 'health_command', 'health_timeout', 'auto_rollback',
    'activation', 'activation_script', 'detection', 'detected_at', 'ci_state', 'ci_run', 'ci_etag', 'ci_checked_at', 'archived_at',
    'shared_paths', 'auto_deploy', 'blue_green', 'backup_role', 'backup_keep', 'image_templates', 'server_builds',
])]
class Project extends Model
{
    use HasFactory;

    // The column defaults, so a model that hasn't been reloaded reads the same.
    protected $attributes = [
        'branch' => 'main',
        'versionable' => true,
        'keep_releases' => 5,
        'dockerized' => false,
        'wait_for_ci' => true,
        'health_timeout' => 180,
        'auto_rollback' => true,
        'activation' => 'builtin',
        'auto_deploy' => false,
        'blue_green' => false,
        'backup_keep' => 5,
    ];

    protected function casts(): array
    {
        return [
            'source' => ProjectSource::class,
            'github_repo_id' => 'integer',
            'versionable' => 'boolean',
            'keep_releases' => 'integer',
            'dockerized' => 'boolean',
            'compose_files' => 'array',
            'app_services' => 'array',
            'delivery_strategies' => 'array',
            'wait_for_ci' => 'boolean',
            'health_timeout' => 'integer',
            'auto_rollback' => 'boolean',
            'activation' => Activation::class,
            'detection' => 'array',
            'detected_at' => 'datetime',
            'shared_paths' => 'array',
            'image_templates' => 'array',
            'server_builds' => 'array',
            'auto_deploy' => 'boolean',
            'blue_green' => 'boolean',
            'backup_keep' => 'integer',
            'ci_state' => CiState::class,
            'ci_run' => 'array',
            'ci_checked_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function envFiles(): HasMany
    {
        return $this->hasMany(ProjectEnvFile::class)->orderBy('path');
    }

    public function hooks(): HasMany
    {
        return $this->hasMany(ProjectHook::class)->orderBy('position')->orderBy('id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(Target::class);
    }

    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function hasGitHub(): bool
    {
        return $this->github_owner !== null && $this->github_repo !== null;
    }

    public function githubFullName(): ?string
    {
        return $this->hasGitHub() ? "{$this->github_owner}/{$this->github_repo}" : null;
    }

    public function githubUrl(): ?string
    {
        return $this->hasGitHub() ? "https://github.com/{$this->githubFullName()}" : null;
    }

    /**
     * Where the code comes from: owner/repo, or the folder as ~/Claude/app.
     */
    public function sourceLabel(): string
    {
        return $this->source === ProjectSource::Github
            ? (string) $this->githubFullName()
            : Format::homePath($this->local_path);
    }

    public function deployModeLabel(): string
    {
        return $this->versionable ? "Releases · keep {$this->keep_releases}" : 'In place';
    }

    public function detected(): Detection
    {
        return Detection::from($this->detection);
    }

    /**
     * Folders every release shares, relative to the project: the list from
     * the settings, or storage/ for each Laravel app found in it.
     *
     * @return list<string>
     */
    public function sharedPaths(): array
    {
        if ($this->shared_paths !== null) {
            return self::normalizePaths($this->shared_paths);
        }

        $paths = [];
        foreach ($this->detected()->apps() as $app) {
            if (($app['name'] ?? null) === 'Laravel') {
                $base = trim((string) ($app['path'] ?? ''), './');
                $paths[] = ($base === '' ? '' : $base.'/').'storage';
            }
        }

        return self::normalizePaths($paths);
    }

    /**
     * Relative paths inside the project, each once: no leading slash, no "..".
     *
     * @param  array<int, mixed>  $paths
     * @return list<string>
     */
    public static function normalizePaths(array $paths): array
    {
        $clean = [];

        foreach ($paths as $path) {
            $path = trim((string) $path, " \t/");

            if ($path !== '' && ! in_array('..', explode('/', $path), true) && preg_match('/^[A-Za-z0-9._\/-]+$/', $path) === 1) {
                $clean[] = $path;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * How a commit's images are named, one row per compose variable that pins
     * one: the project's own list, or what detection makes of the workflow and
     * the compose file while nobody has corrected it.
     *
     * @return list<array{var: string, kind: string, image: string, tag: string}>
     */
    public function imageTemplates(): array
    {
        return $this->image_templates !== null
            ? self::normalizeImageTemplates($this->image_templates)
            : ImagePlan::detected($this);
    }

    /**
     * How the server builds the images the compose file only names (see
     * ServerBuild): one row per service, normalised. Empty when the compose
     * file builds its own, or the project ships prebuilt images.
     *
     * @return list<array{service: string, context: string, dockerfile: string, target: ?string, args: array<string, string>}>
     */
    public function serverBuilds(): array
    {
        $rows = [];

        foreach ((array) ($this->server_builds ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $service = trim((string) ($row['service'] ?? ''));
            $dockerfile = trim((string) ($row['dockerfile'] ?? ''));

            if ($service === '' || $dockerfile === '') {
                continue;
            }

            $args = [];

            foreach ((array) ($row['args'] ?? []) as $name => $value) {
                if (is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 && is_scalar($value)) {
                    $args[$name] = (string) $value;
                }
            }

            $target = trim((string) ($row['target'] ?? ''));

            $rows[] = [
                'service' => $service,
                'context' => trim((string) ($row['context'] ?? '')) ?: '.',
                'dockerfile' => $dockerfile,
                'target' => $target === '' ? null : $target,
                'args' => $args,
            ];
        }

        return $rows;
    }

    /**
     * Image templates as stored: a compose variable name, whether it holds a
     * tag or a whole image, and the image and tag it's pinned to, which may
     * use {owner}, {repo}, {sha} and {sha7}.
     *
     * @param  array<int, mixed>  $input
     * @return list<array{var: string, kind: string, image: string, tag: string}>
     */
    public static function normalizeImageTemplates(array $input): array
    {
        $rows = [];

        foreach ($input as $row) {
            if (! is_array($row)) {
                continue;
            }

            $var = trim((string) ($row['var'] ?? ''));
            $image = trim((string) ($row['image'] ?? ''));
            $tag = trim((string) ($row['tag'] ?? ''));

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $var) !== 1 || $image === '' || $tag === '') {
                continue;
            }

            if (in_array($var, array_column($rows, 'var'), true)) {
                continue;
            }

            $rows[] = ['var' => $var, 'kind' => ($row['kind'] ?? null) === 'image' ? 'image' : 'tag', 'image' => $image, 'tag' => $tag];
        }

        return $rows;
    }

    /**
     * The delivery strategies in priority order. Any the stored list doesn't
     * mention come last, switched off.
     *
     * @return list<array{key: DeliveryStrategyKey, enabled: bool}>
     */
    public function strategies(): array
    {
        return array_map(
            fn (array $item) => ['key' => DeliveryStrategyKey::from($item['key']), 'enabled' => $item['enabled']],
            self::normalizeStrategies($this->delivery_strategies ?? []),
        );
    }

    /**
     * A priority list as stored: known keys only, each once, every strategy
     * present (the ones not mentioned last, switched off).
     *
     * @param  array<int, mixed>  $input
     * @return list<array{key: string, enabled: bool}>
     */
    public static function normalizeStrategies(array $input): array
    {
        $list = [];

        foreach ($input as $item) {
            $key = is_array($item) ? DeliveryStrategyKey::tryFrom((string) ($item['key'] ?? '')) : null;

            if ($key !== null && ! in_array($key->value, array_column($list, 'key'), true)) {
                $list[] = ['key' => $key->value, 'enabled' => (bool) ($item['enabled'] ?? false)];
            }
        }

        foreach (DeliveryStrategyKey::cases() as $key) {
            if (! in_array($key->value, array_column($list, 'key'), true)) {
                $list[] = ['key' => $key->value, 'enabled' => false];
            }
        }

        return $list;
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Names::folder($name);
        $slug = $base;

        for ($i = 2; static::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
