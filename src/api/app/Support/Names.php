<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The two names a project needs on a server: its folder and its Compose
 * project (stack). Both follow the rules of the tools that use them.
 */
final class Names
{
    // Lowercase letters, digits, dots, dashes and underscores; no leading or trailing punctuation.
    public const FOLDER_PATTERN = '/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/';

    // docker compose: lowercase letters, digits, dashes and underscores, starting with a letter or digit.
    public const STACK_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/';

    public static function folder(string $value): string
    {
        $name = strtolower(Str::ascii($value));
        $name = (string) preg_replace('/[^a-z0-9._-]+/', '-', $name);
        $name = (string) preg_replace('/([._-])[._-]+/', '$1', $name);
        $name = trim(substr($name, 0, 64), '._-');

        return $name !== '' ? $name : 'project';
    }

    public static function stack(string $value): string
    {
        $name = strtolower(Str::ascii($value));
        $name = (string) preg_replace('/[^a-z0-9_-]+/', '-', $name);
        $name = (string) preg_replace('/([_-])[_-]+/', '$1', $name);
        $name = trim(substr($name, 0, 63), '_-');

        return $name !== '' ? $name : 'app';
    }

    public static function isFolder(string $value): bool
    {
        return (bool) preg_match(self::FOLDER_PATTERN, $value) && ! str_contains($value, '..');
    }

    public static function isStack(string $value): bool
    {
        return (bool) preg_match(self::STACK_PATTERN, $value);
    }
}
