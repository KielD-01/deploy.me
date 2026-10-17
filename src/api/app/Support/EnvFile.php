<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A minimal .env editor. Reads KEY=value lines, replaces keys in place or
 * appends them, and leaves comments and ordering alone. Writes atomically.
 */
final class EnvFile
{
    private readonly string $path;

    /**
     * Defaults to the application's own .env, so the container can inject it.
     */
    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_path('.env');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function get(string $key): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        foreach (file($this->path, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/', $line, $match)) {
                return trim($match[1], "\"'");
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $values
     */
    public function set(array $values): void
    {
        $contents = is_file($this->path) ? (string) file_get_contents($this->path) : '';

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException("Invalid env key [{$key}].");
            }

            $line = $key.'='.$this->quote($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $contents = preg_match($pattern, $contents)
                ? preg_replace_callback($pattern, fn () => $line, $contents, 1)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        $tmp = $this->path.'.tmp';
        file_put_contents($tmp, $contents);
        rename($tmp, $this->path);
    }

    private function quote(string $value): string
    {
        return preg_match('~^[A-Za-z0-9_.:/@+-]*$~', $value)
            ? $value
            : '"'.addcslashes($value, '"\\').'"';
    }
}
