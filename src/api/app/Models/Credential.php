<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GitHub token. It reaches the repositories of its own account and of the
 * organizations that account belongs to (`owners`).
 */
#[Fillable([
    'provider', 'login', 'owners', 'token', 'token_hint', 'token_type', 'scopes', 'expires_at', 'verified_at', 'last_error',
])]
class Credential extends Model
{
    use HasFactory;

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'owners' => 'array',
            'token' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function covers(string $owner): bool
    {
        return in_array(strtolower($owner), array_map('strtolower', $this->owners ?? []), true);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * The token for an owner: the owner's own account first, then any token
     * whose account belongs to that organization.
     */
    public static function forOwner(string $owner): ?self
    {
        $credentials = static::query()->where('provider', 'github')->orderBy('id')->get();

        return $credentials->first(fn (self $credential) => strcasecmp($credential->login, $owner) === 0)
            ?? $credentials->first(fn (self $credential) => $credential->covers($owner));
    }

    /**
     * Every owner any token reaches, for owner pickers.
     *
     * @return array<string, int> owner => credential id
     */
    public static function ownerChoices(): array
    {
        $choices = [];

        foreach (static::query()->where('provider', 'github')->orderBy('id')->get() as $credential) {
            foreach ([$credential->login, ...($credential->owners ?? [])] as $owner) {
                $choices[$owner] ??= $credential->id;
            }
        }

        return $choices;
    }
}
