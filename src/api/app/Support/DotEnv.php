<?php

namespace App\Support;

/**
 * Reads a project's dotenv file for display: KEY=value lines, `export `
 * allowed, quotes and trailing comments removed. The file itself is stored
 * and shipped byte for byte; this is only for listing and masking keys.
 */
final class DotEnv
{
    /**
     * @return array<string, string> key => value, the last definition wins
     */
    public static function parse(string $content): array
    {
        $values = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*(.*)$/', $line, $match)) {
                $values[$match[1]] = self::unquote($match[2]);
            }
        }

        return $values;
    }

    /**
     * Whether a key name looks like it holds a secret, so the UI masks it.
     */
    public static function isSecret(string $key): bool
    {
        return (bool) preg_match('/(PASS(WORD|WD)?|SECRET|TOKEN|PRIVATE|CREDENTIAL|API_?KEY|_KEY$|^KEY$|AUTH|DSN|SALT|COOKIE|SIGNATURE|WEBHOOK)/i', $key);
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $end = strrpos($value, $value[0]);

            return $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $value));
    }
}
