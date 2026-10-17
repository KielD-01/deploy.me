<?php

namespace App\Platform\Git;

/**
 * The GitHub repository behind a git remote URL: https, ssh, scp-like
 * (git@github.com:owner/repo), or an ~/.ssh/config alias whose HostName is
 * github.com (gh-work:owner/repo).
 */
final class GitRemote
{
    private const PATH = '(?<owner>[A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/(?<repo>[A-Za-z0-9._-]+?)(?:\.git)?/?';

    public function __construct(
        public readonly string $owner,
        public readonly string $repo,
        public readonly string $transport,
        public readonly ?string $alias = null,
    ) {}

    /**
     * @param  (callable(string): bool)|null  $isGitHubAlias  whether an ssh alias resolves to github.com
     */
    public static function parse(string $url, ?callable $isGitHubAlias = null): ?self
    {
        $url = trim($url);
        $path = self::PATH;

        if (preg_match("~^(?:https?|git)://(?:[^@/]+@)?(?:www\.)?github\.com(?::\d+)?/{$path}$~i", $url, $m)) {
            return new self($m['owner'], $m['repo'], 'https');
        }

        if (preg_match("~^ssh://(?:[^@/]+@)?(?:ssh\.)?github\.com(?::\d+)?/{$path}$~i", $url, $m)) {
            return new self($m['owner'], $m['repo'], 'ssh');
        }

        if (preg_match("~^(?:[^@/:]+@)?(?<host>[A-Za-z0-9._-]+):{$path}$~", $url, $m)) {
            if (strcasecmp($m['host'], 'github.com') === 0) {
                return new self($m['owner'], $m['repo'], 'ssh');
            }
            if ($isGitHubAlias !== null && $isGitHubAlias($m['host'])) {
                return new self($m['owner'], $m['repo'], 'alias', $m['host']);
            }
        }

        return null;
    }

    public function fullName(): string
    {
        return "{$this->owner}/{$this->repo}";
    }

    public function httpsUrl(): string
    {
        return "https://github.com/{$this->owner}/{$this->repo}.git";
    }

    /**
     * Drops credentials someone embedded in an http(s) URL, anywhere in the
     * text: https://user:token@github.com/… becomes https://github.com/….
     */
    public static function redact(string $text): string
    {
        return (string) preg_replace('~(https?://)[^@/\s]+@~i', '$1', $text);
    }
}
