<?php

namespace App\Support;

use App\Enums\DeploymentStatus;
use App\Enums\SyncRunStatus;
use App\Models\Deployment;
use App\Models\SyncRun;

/**
 * deploy.me's own updates. The app only looks and says (this class, the daily
 * CheckForUpdate, Settings → Updates): applying one rebuilds and restarts the
 * containers it runs in, so that's bin/update on the host, whose last result
 * lands in the state file read here.
 */
final class Updates
{
    public const MODES = ['off' => 'Off', 'patch' => 'Patch releases only', 'all' => 'Every release'];

    public static function current(): string
    {
        return (string) config('deployme.version');
    }

    /**
     * The newest release GitHub announced at the last check.
     *
     * @return array{version: string, tag: string, name: string, notes: string, url: string, published_at: ?string, checked_at: string, error: ?string}|null
     */
    public static function latest(): ?array
    {
        $stored = json_decode(Settings::string('update_latest'), true);

        return is_array($stored) && isset($stored['version'], $stored['tag']) ? $stored : null;
    }

    /**
     * The release to tell the user about: newer than this one, else null.
     *
     * @return array{version: string, tag: string, name: string, notes: string, url: string, published_at: ?string, checked_at: string, error: ?string, kind: string}|null
     */
    public static function available(): ?array
    {
        $latest = self::latest();
        $kind = $latest !== null ? Version::kind(self::current(), $latest['version']) : null;

        return $kind !== null ? [...$latest, 'kind' => $kind] : null;
    }

    /**
     * Whether the host's nightly job is installed (bin/update auto patch|all).
     */
    public static function agentInstalled(): bool
    {
        return is_file((string) config('deployme.update.agent_file'));
    }

    public static function mode(): string
    {
        $mode = Settings::string('auto_update');

        return array_key_exists($mode, self::MODES) ? $mode : 'off';
    }

    public static function setMode(string $mode): void
    {
        if (! array_key_exists($mode, self::MODES)) {
            throw new \InvalidArgumentException("[{$mode}] isn't one of: ".implode(', ', array_keys(self::MODES)).'.');
        }

        Settings::setString('auto_update', $mode);
    }

    /**
     * A deploy or a folder sync in flight: nothing restarts the stack under it.
     */
    public static function busy(): bool
    {
        return Deployment::query()->whereIn('status', DeploymentStatus::ACTIVE)->exists()
            || SyncRun::query()->whereIn('status', SyncRunStatus::ACTIVE)->exists();
    }

    /**
     * What bin/update would do now. $auto is the nightly run: it applies only
     * what the mode allows, and never while something is running.
     *
     * @return array{current: string, latest: ?string, kind: ?string, mode: string, busy: bool, action: string, reason: string}
     */
    public static function plan(bool $auto): array
    {
        $available = self::available();
        $mode = self::mode();
        $busy = self::busy();

        $plan = fn (string $action, string $reason) => [
            'current' => self::current(),
            'latest' => $available['version'] ?? self::latest()['version'] ?? null,
            'kind' => $available['kind'] ?? null,
            'mode' => $mode,
            'busy' => $busy,
            'action' => $action,
            'reason' => $reason,
        ];

        return match (true) {
            $available === null => $plan('none', self::latest() === null ? 'No check has run yet.' : 'This is the newest release.'),
            $auto && $mode === 'off' => $plan('none', 'Automatic updates are off.'),
            $auto && $mode === 'patch' && $available['kind'] !== 'patch' => $plan('none', "{$available['version']} is a {$available['kind']} release: it waits for you (bin/update)."),
            $busy => $plan('busy', 'A deploy or a folder sync is running.'),
            default => $plan('apply', "{$available['version']} is a {$available['kind']} release."),
        };
    }

    /**
     * What bin/update wrote last: {at, result: ok|rolled_back|failed, from, to, message, backup}.
     *
     * @return array{at: string, result: string, from: string, to: string, message: string, backup: ?string}|null
     */
    public static function lastRun(): ?array
    {
        $file = (string) config('deployme.update.state_file');
        $state = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($state) && isset($state['result'], $state['at']) ? [
            'at' => (string) $state['at'],
            'result' => (string) $state['result'],
            'from' => (string) ($state['from'] ?? ''),
            'to' => (string) ($state['to'] ?? ''),
            'message' => (string) ($state['message'] ?? ''),
            'backup' => isset($state['backup']) ? (string) $state['backup'] : null,
        ] : null;
    }
}
