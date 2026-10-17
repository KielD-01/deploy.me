<?php

namespace App\Support;

final class Format
{
    public static function bytes(int|float|null $bytes, int $precision = 1): string
    {
        if ($bytes === null) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        $number = $unit === 0 ? (string) (int) $value : rtrim(rtrim(number_format($value, $precision, '.', ''), '0'), '.');

        return "{$number} {$units[$unit]}";
    }

    /**
     * Rough uptime: "38 days", "5 h", "12 min".
     */
    public static function uptime(?int $seconds): string
    {
        return match (true) {
            $seconds === null => '—',
            $seconds >= 172800 => intdiv($seconds, 86400).' days',
            $seconds >= 86400 => '1 day',
            $seconds >= 3600 => intdiv($seconds, 3600).' h',
            default => max(1, intdiv($seconds, 60)).' min',
        };
    }

    public static function mhz(?int $mhz): ?string
    {
        return $mhz ? number_format($mhz / 1000, 2).' GHz' : null;
    }

    /**
     * A host path with the home folder written as ~, the way it reads on the Mac.
     */
    public static function homePath(?string $path): string
    {
        if ($path === null || $path === '') {
            return '—';
        }

        $home = rtrim((string) config('deployme.host_home'), '/');

        return $home !== '' && ($path === $home || str_starts_with($path, $home.'/'))
            ? '~'.substr($path, strlen($home))
            : $path;
    }
}
