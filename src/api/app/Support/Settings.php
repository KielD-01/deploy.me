<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * App-wide settings from Settings → Schedules, with their defaults.
 */
final class Settings
{
    public const DEFAULTS = [
        // Seconds between health checks of each target; 0 switches them off.
        'health_interval' => 60,
        // Seconds between GitHub Actions checks of each project.
        'ci_poll_interval' => 300,
        // Days a run's log stays in the database, then days its gzipped archive stays.
        'log_retention_days' => 14,
        'archive_retention_days' => 90,
        // Days of server probe history.
        'snapshot_retention_days' => 30,
        // Days of each target's health check history.
        'health_retention_days' => 7,
        // Days the audit log keeps.
        'audit_retention_days' => 365,
    ];

    public const CHOICES = [
        'health_interval' => [60 => 'Every minute', 300 => 'Every 5 minutes', 900 => 'Every 15 minutes', 0 => 'Off'],
        'ci_poll_interval' => [60 => 'Every minute', 300 => 'Every 5 minutes', 900 => 'Every 15 minutes'],
        'log_retention_days' => [7 => '7 days', 14 => '14 days', 30 => '30 days'],
        'archive_retention_days' => [30 => '30 days', 90 => '90 days', 365 => 'a year'],
        'snapshot_retention_days' => [7 => '7 days', 30 => '30 days', 90 => '90 days'],
        'health_retention_days' => [7 => '7 days', 30 => '30 days', 90 => '90 days'],
        'audit_retention_days' => [30 => '30 days', 90 => '90 days', 365 => 'a year', 1095 => 'three years'],
    ];

    /**
     * Text settings, edited in Settings → Alerts. Empty means "not set up",
     * which is what keeps a fresh install from trying to send anything.
     */
    public const STRINGS = [
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
    ];

    // Stored encrypted, like every other credential here.
    private const SECRETS = ['telegram_bot_token'];

    public static function int(string $key): int
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }

        return (int) Cache::remember(self::cacheKey($key), 60, fn () => Setting::query()->find($key)?->value ?? self::DEFAULTS[$key]);
    }

    public static function set(string $key, int $value): void
    {
        if (! array_key_exists($value, self::CHOICES[$key] ?? [])) {
            throw new InvalidArgumentException("[{$value}] isn't a choice for [{$key}].");
        }

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }

    public static function string(string $key): string
    {
        if (! array_key_exists($key, self::STRINGS)) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }

        $stored = (string) Cache::remember(self::cacheKey($key), 60, fn () => Setting::query()->find($key)?->value ?? self::STRINGS[$key]);

        if ($stored === '' || ! in_array($key, self::SECRETS, true)) {
            return $stored;
        }

        // A key change (or a hand-edited row) leaves it unreadable: that reads
        // as "not set up" rather than throwing inside a deploy.
        return rescue(fn () => Crypt::decryptString($stored), '', report: false);
    }

    public static function setString(string $key, string $value): void
    {
        if (! array_key_exists($key, self::STRINGS)) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }

        Setting::query()->updateOrCreate(['key' => $key], [
            'value' => $value !== '' && in_array($key, self::SECRETS, true) ? Crypt::encryptString($value) : $value,
        ]);
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return "deployme:setting:{$key}";
    }
}
