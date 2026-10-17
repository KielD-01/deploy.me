<?php

namespace App\Actions\Targets;

use App\Deploy\Compose;
use App\Enums\TargetHealth;
use App\Events\TargetHealthChanged;
use App\Models\Target;
use App\Platform\Ssh\Exceptions\SshException;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshErrors;
use App\Platform\Ssh\SshResult;
use App\Support\Alert;
use Illuminate\Support\Str;

/**
 * Checks a deployed target the way a deploy's health step does, from the
 * server itself: a Docker project's app services up, and the health URL
 * answering. A quick look (a try or two), not a wait.
 */
class CheckTargetHealth
{
    // Seconds a check gives the app before calling it unhealthy.
    public const PATIENCE = 5;

    public function __construct(private readonly SshClient $ssh) {}

    public function __invoke(Target $target): ?TargetHealth
    {
        $target->loadMissing(['project', 'server', 'currentRelease']);
        $project = $target->project;
        $release = $target->currentRelease;
        $started = microtime(true);
        $passed = [];
        $failed = [];

        try {
            if ($release !== null && Compose::builtin($project)) {
                $run = $this->run($target, RemoteScript::file('deploy-compose-health', [
                    ...Compose::vars($project, $release->path, 'ps', $project->app_services ?? []),
                    'TIMEOUT' => self::PATIENCE,
                    'INTERVAL' => self::PATIENCE,
                ]));

                if ($run->successful()) {
                    $passed[] = 'services up';
                } else {
                    $failed[] = self::marker($run, 'waiting') ?? self::lastWords($run, 'the services aren’t up');
                }
            }

            if (($url = (string) $project->health_url) !== '') {
                $run = $this->run($target, RemoteScript::file('deploy-health', ['URL' => $url, 'TIMEOUT' => self::PATIENCE, 'INTERVAL' => self::PATIENCE]));

                if ($run->successful()) {
                    $passed[] = 'HTTP '.(self::marker($run, 'code') ?? '200');
                } else {
                    $failed[] = self::marker($run, 'last') !== null ? "{$url} gives ".self::marker($run, 'last') : self::lastWords($run, "{$url} didn’t answer");
                }
            }
        } catch (SshException $e) {
            $failed[] = $e->userMessage();
        }

        if ($passed === [] && $failed === []) {
            return null;
        }

        $health = $failed === [] ? TargetHealth::Healthy : TargetHealth::Unhealthy;
        $detail = Str::limit(implode(' · ', $failed === [] ? $passed : $failed), 250);
        $changed = $target->health !== $health || $target->health_detail !== $detail;

        $target->update([
            'health' => $health,
            'health_detail' => $detail,
            'health_checked_at' => now(),
            'unhealthy_since' => $health === TargetHealth::Unhealthy ? ($target->unhealthy_since ?? now()) : null,
        ]);

        // The result before this one would otherwise be gone: the history is
        // what tells a flapping app from one that has always been down.
        $target->healthChecks()->create([
            'health' => $health,
            'detail' => $detail,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'checked_at' => now(),
        ]);

        // Open pages refresh when there's something new to show, not every check.
        if ($changed) {
            rescue(fn () => TargetHealthChanged::dispatch($target->id, $target->project_id, $health->value), report: false);
            rescue(fn () => Alert::healthChanged($target, $health), report: false);
        }

        return $health;
    }

    /**
     * @throws SshException when ssh itself failed
     */
    private function run(Target $target, RemoteScript $script): SshResult
    {
        $result = $this->ssh->run($target->server->ssh_alias, $script, timeout: self::PATIENCE * 2 + 30);

        if ($result->exitCode === 255) {
            throw SshErrors::from($result);
        }

        return $result;
    }

    private static function marker(SshResult $result, string $key): ?string
    {
        return preg_match('/^::deployme::'.$key.'=(.+)$/m', $result->stdout, $match) === 1 ? trim($match[1]) : null;
    }

    private static function lastWords(SshResult $result, string $fallback): string
    {
        foreach (array_reverse(preg_split('/\R/', trim($result->stdout)) ?: []) as $line) {
            if (str_starts_with($line, '✗ ')) {
                return substr($line, strlen('✗ '));
            }
        }

        return $fallback;
    }
}
