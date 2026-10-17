<?php

namespace App\Support;

/**
 * Release numbers, as the tags carry them: v1.2.3 or 1.2.3.
 */
final class Version
{
    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function parse(string $version): ?array
    {
        if (preg_match('/^v?(\d{1,6})\.(\d{1,6})(?:\.(\d{1,6}))?$/', trim($version), $match) !== 1) {
            return null;
        }

        return [(int) $match[1], (int) $match[2], (int) ($match[3] ?? 0)];
    }

    /**
     * 'patch', 'minor' or 'major' when $latest is newer than $current; null when it
     * isn't, or when either isn't a release number.
     */
    public static function kind(string $current, string $latest): ?string
    {
        $from = self::parse($current);
        $to = self::parse($latest);

        if ($from === null || $to === null || $to <= $from) {
            return null;
        }

        return match (true) {
            $to[0] !== $from[0] => 'major',
            $to[1] !== $from[1] => 'minor',
            default => 'patch',
        };
    }

    public static function normalize(string $version): string
    {
        $parts = self::parse($version);

        return $parts === null ? '' : implode('.', $parts);
    }
}
