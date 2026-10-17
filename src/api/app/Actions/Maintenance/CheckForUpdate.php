<?php

namespace App\Actions\Maintenance;

use App\Support\Settings;
use App\Support\Updates;
use App\Support\Version;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Asks GitHub which release is the newest and keeps the answer. One anonymous
 * GET, nothing about this machine is sent. A failure keeps the last good answer
 * and notes the error beside it; the release's notes are text from the internet
 * and are only ever shown, escaped.
 */
class CheckForUpdate
{
    /**
     * @param  bool  $force  ignore the "Off" choice (the button and the command)
     * @return array{ok: bool, message: string}
     */
    public function __invoke(bool $force = false): array
    {
        if (! $force && Settings::int('update_check') === 0) {
            return ['ok' => true, 'message' => 'Checking for updates is off.'];
        }

        $repository = (string) config('deployme.update.repository');
        $previous = Updates::latest();

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'deploy.me'])
                ->get("https://api.github.com/repos/{$repository}/releases/latest");

            if ($response->status() === 404) {
                // Nothing released yet is an answer, not a failure.
                return $this->keep($previous, null, 'No release has been published yet.');
            }

            $release = $response->throw()->json();
        } catch (\Throwable $e) {
            return $this->keep($previous, 'GitHub didn’t answer: '.Str::limit($e->getMessage(), 120), 'GitHub didn’t answer.');
        }

        $version = Version::normalize((string) ($release['tag_name'] ?? ''));
        $url = (string) ($release['html_url'] ?? '');

        if ($version === '' || ! str_starts_with($url, 'https://github.com/')) {
            return $this->keep($previous, 'GitHub’s answer wasn’t a release number.', 'GitHub’s answer wasn’t a release number.');
        }

        Settings::setString('update_latest', (string) json_encode([
            'version' => $version,
            'tag' => 'v'.$version,
            'name' => Str::limit((string) ($release['name'] ?? ''), 120, ''),
            'notes' => Str::limit((string) ($release['body'] ?? ''), 4000, '…'),
            'url' => $url,
            'published_at' => isset($release['published_at']) ? (string) $release['published_at'] : null,
            'checked_at' => now()->toIso8601String(),
            'error' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['ok' => true, 'message' => Version::kind(Updates::current(), $version) !== null
            ? "{$version} is out (this is ".Updates::current().').'
            : 'This is the newest release ('.Updates::current().').'];
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @return array{ok: bool, message: string}
     */
    private function keep(?array $previous, ?string $error, string $message): array
    {
        if ($previous !== null) {
            Settings::setString('update_latest', (string) json_encode([...$previous, 'error' => $error, 'checked_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return ['ok' => $error === null, 'message' => $message];
    }
}
