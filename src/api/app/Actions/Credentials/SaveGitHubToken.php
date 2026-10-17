<?php

namespace App\Actions\Credentials;

use App\Enums\AuditAction;
use App\Models\Credential;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;
use App\Support\Audit;
use Illuminate\Support\Carbon;

/**
 * Checks a GitHub token with GitHub, then stores it with what it can reach:
 * its account, the organizations and collaborators whose repositories it
 * sees, its scopes and its expiry. A new token for the same account replaces
 * the old one.
 */
class SaveGitHubToken
{
    /**
     * @throws GitHubException
     */
    public function __invoke(string $token): Credential
    {
        $github = new GitHubClient($token);
        $account = $github->whoami();

        $owners = collect($github->repositories())
            ->pluck('owner.login')
            ->prepend($account['login'])
            ->filter()
            ->unique(fn (string $owner) => strtolower($owner))
            ->values()
            ->all();

        $credential = Credential::query()->updateOrCreate(
            ['provider' => 'github', 'login' => $account['login']],
            [
                'owners' => $owners,
                'token' => $token,
                'token_hint' => self::hint($token),
                'token_type' => self::type($token),
                'scopes' => $account['scopes'],
                'expires_at' => $account['expires_at'] !== null ? Carbon::parse($account['expires_at']) : null,
                'verified_at' => now(),
                'last_error' => null,
            ],
        );

        Audit::record(AuditAction::CredentialSaved, [
            'subject' => "GitHub token for {$credential->login}",
            // The hint, never the token: ghp_••••3f9A.
            'detail' => $credential->token_hint.' · reaches '.implode(', ', array_slice($owners, 0, 6)),
        ]);

        return $credential;
    }

    public static function looksLikeToken(string $token): bool
    {
        return (bool) preg_match('/^(ghp_|gho_|ghu_|github_pat_)[A-Za-z0-9_]{20,255}$/', $token);
    }

    public static function type(string $token): string
    {
        return match (true) {
            str_starts_with($token, 'ghp_') => 'classic',
            str_starts_with($token, 'github_pat_') => 'fine_grained',
            str_starts_with($token, 'gho_') => 'oauth',
            default => 'other',
        };
    }

    /**
     * Enough to recognise the token, never enough to use it: ghp_••••3f9A.
     */
    public static function hint(string $token): string
    {
        $prefix = str_starts_with($token, 'github_pat_') ? 'github_pat_' : substr($token, 0, 4);

        return $prefix.'••••'.substr($token, -4);
    }
}
