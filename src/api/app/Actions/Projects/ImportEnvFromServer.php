<?php

namespace App\Actions\Projects;

use App\Deploy\Compose;
use App\Enums\EnvFileRole;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Target;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use Illuminate\Support\Str;

/**
 * Brings the env files a project already runs with on the server into
 * deploy.me, encrypted and versioned like the ones typed into it. A project
 * deployed before deploy.me existed has them only on the server, and without
 * them deploy.me can't say which database to back up before it migrates.
 */
class ImportEnvFromServer
{
    public function __construct(private readonly SshClient $ssh) {}

    /**
     * @return array{imported: list<string>, unchanged: list<string>, log: string}
     */
    public function __invoke(Target $target): array
    {
        $project = $target->project;
        $paths = self::candidates($project);

        $run = $this->ssh->run($target->server->ssh_alias, RemoteScript::file('deploy-read-env', [
            'TARGET' => $target->path,
            'PATHS' => implode("\n", $paths),
        ]), timeout: 60);

        $imported = $unchanged = [];

        foreach (self::files($run->stdout) as $path => $content) {
            $file = $project->envFiles()->firstWhere('path', $path);

            if ($file === null) {
                $project->envFiles()->create([
                    'path' => $path,
                    'role' => self::role($project, $path),
                    ...ProjectEnvFile::contentAttributes($content),
                ]);
                $imported[] = $path;

                continue;
            }

            $file->revise($content) ? $imported[] = $path : $unchanged[] = $path;
        }

        // The contents never go in the log: only which files came back.
        return [
            'imported' => $imported,
            'unchanged' => $unchanged,
            'log' => preg_replace('/^::deployme::file=.*$/m', '', $run->stdout) ?? '',
        ];
    }

    /**
     * Where a project's env files live: what its compose file reads, what it
     * already has here, and the root .env every stack has.
     *
     * @return list<string>
     */
    public static function candidates(Project $project): array
    {
        $compose = Compose::detected($project) ?? [];

        $paths = [
            ...array_map(fn (string $path) => Str::replaceStart('./', '', $path), (array) ($compose['env_files'] ?? [])),
            ...$project->envFiles()->pluck('path')->all(),
            '.env',
        ];

        return array_values(array_unique(array_filter($paths, fn (string $path) => $path !== '' && ! str_contains($path, '..'))));
    }

    /**
     * @return array<string, string> path => contents
     */
    private static function files(string $stdout): array
    {
        preg_match_all('/^::deployme::file=([^:]+):(\S*)$/m', $stdout, $matches, PREG_SET_ORDER);

        $files = [];

        foreach ($matches as $match) {
            $content = base64_decode($match[2], true);

            if ($content !== false && $content !== '') {
                $files[$match[1]] = str_replace("\r\n", "\n", $content);
            }
        }

        return $files;
    }

    /**
     * A file beside the compose file is what Compose itself reads; anything
     * deeper belongs to the application.
     */
    private static function role(Project $project, string $path): EnvFileRole
    {
        return ! str_contains($path, '/') && $project->dockerized ? EnvFileRole::Compose : EnvFileRole::App;
    }
}
