<?php

namespace App\Platform\Ssh;

use InvalidArgumentException;

/**
 * One literal `Host` entry from an ssh config file.
 */
final readonly class SshAlias
{
    public function __construct(
        public string $name,
        public ?string $hostName,
        public string $sourceFile,
        public int $line,
    ) {}

    /**
     * Aliases like `gh-personal` that point at a git host are git identities, not servers.
     */
    public function looksLikeGitHost(): bool
    {
        return $this->hostName !== null
            && (bool) preg_match('/(^|\.)(github\.com|gitlab\.com|bitbucket\.org)$/i', $this->hostName);
    }

    /**
     * Aliases end up as an ssh argument; anything that could read as an option is refused.
     */
    public static function assertValid(string $alias): void
    {
        if (! preg_match('/^[A-Za-z0-9_][A-Za-z0-9._@-]{0,254}$/', $alias)) {
            throw new InvalidArgumentException("Invalid ssh alias [{$alias}].");
        }
    }
}
