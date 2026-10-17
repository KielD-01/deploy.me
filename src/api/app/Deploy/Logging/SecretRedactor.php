<?php

namespace App\Deploy\Logging;

/**
 * Masks secrets in log lines: the values deploy.me knows are secret (env
 * files, tokens) and anything shaped like a GitHub token or URL credentials.
 */
final class SecretRedactor
{
    public const MASK = '••••••';

    /** @var list<string> */
    private array $secrets = [];

    public function add(string $secret): void
    {
        $secret = trim($secret);

        // Short values would mask ordinary words.
        if (strlen($secret) < 6 || in_array($secret, $this->secrets, true)) {
            return;
        }

        $this->secrets[] = $secret;
        // Longest first, so a secret that contains another is masked whole.
        usort($this->secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));
    }

    public function redact(string $line): string
    {
        if ($this->secrets !== []) {
            $line = str_replace($this->secrets, self::MASK, $line);
        }

        return (string) preg_replace(
            ['/\bgh[pousr]_[A-Za-z0-9]{20,}/', '/\bgithub_pat_[A-Za-z0-9_]{20,}/', '~(https?://)[^@/\s]+@~i'],
            [self::MASK, self::MASK, '$1'],
            $line,
        );
    }
}
