<?php

namespace App\Actions\Targets;

use App\Deploy\Compose;
use App\Deploy\Conversion\FolderScan;
use App\Deploy\Conversion\RepositoryComparison;
use App\Events\TargetFolderRead;
use App\Models\Project;
use App\Models\Target;
use App\Platform\Ssh\Exceptions\SshException;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshErrors;
use RuntimeException;
use Throwable;

/**
 * Reads a target's folder for converting it to releases: what's in it (sizes,
 * a git checkout, the stack's mounts, what its compose files name) and, when
 * asked, which commit it matches, file by file. Changes nothing on the
 * server; the target keeps the result.
 */
class ReadFolder
{
    public function __construct(
        private readonly SshClient $ssh,
        private readonly RepositoryComparison $comparison,
    ) {}

    /**
     * @return array<string, mixed> the read, as the target keeps it
     */
    public function __invoke(Target $target, bool $compare = true): array
    {
        $target->loadMissing(['project', 'server']);
        $project = $target->project;
        $asked = $this->candidates($project);

        try {
            $scan = FolderScan::parse($this->run($target, [
                'STACK' => $project->dockerized ? (string) $project->stack_name : '',
                'FILES' => implode("\n", $project->dockerized ? Compose::files($project) : []),
                'PATHS' => implode("\n", $asked),
                'ONLY_PATHS' => 0,
            ]), $target->path);

            // Paths the server's compose files name that nobody asked about yet.
            $more = array_values(array_diff([...array_keys($scan['env_files']), ...$scan['bind_sources']], $asked, ['.']));
            if ($more !== []) {
                $scan['paths'] = [...$scan['paths'], ...FolderScan::paths($this->run($target, [
                    'STACK' => '', 'FILES' => '', 'PATHS' => implode("\n", $more), 'ONLY_PATHS' => 1,
                ]))];
            }

            if ($compare && $scan['git'] === null && $scan['items'] !== []) {
                $scan['comparison'] = $scan['rsync']
                    ? $this->comparison->compare($target, $scan)
                    : ['error' => 'rsync isn’t on the server, so the files weren’t compared with the repository.'];
            }
        } catch (Throwable $e) {
            $scan = [
                ...($target->folder_scan ?? []),
                'error' => $e instanceof SshException ? $e->userMessage() : $e->getMessage(),
            ];
        }

        $scan['reading'] = null;
        $scan['read_at'] = now()->toIso8601String();
        $target->forceFill(['folder_scan' => $scan, 'folder_scanned_at' => now()])->save();

        rescue(fn () => TargetFolderRead::dispatch($target->id, $target->project_id), report: false);

        return $scan;
    }

    /**
     * @param  array<string, string|int>  $vars
     *
     * @throws SshException|RuntimeException
     */
    private function run(Target $target, array $vars): string
    {
        $result = $this->ssh->run($target->server->ssh_alias, RemoteScript::file('deploy-convert-scan', ['TARGET' => $target->path, ...$vars]), timeout: 300);

        if ($result->exitCode === 255) {
            throw SshErrors::from($result);
        }

        if (! $result->successful()) {
            $failure = collect(preg_split('/\R/', $result->stdout) ?: [])->last(fn (string $line) => str_starts_with($line, '✗ '));

            throw new RuntimeException($failure !== null ? substr($failure, strlen('✗ ')) : 'Reading the folder failed.');
        }

        return $result->stdout;
    }

    /**
     * Paths inside the folder worth looking for: env files deploy.me keeps
     * and the ones the project's compose files name, and its shared paths.
     *
     * @return list<string>
     */
    private function candidates(Project $project): array
    {
        return Project::normalizePaths([
            ...$project->envFiles()->pluck('path')->all(),
            ...collect($project->detected()->composeFiles())->flatMap(fn (array $file) => (array) ($file['env_files'] ?? []))
                ->map(fn ($path) => preg_replace('~^\./~', '', (string) $path))->all(),
            ...$project->sharedPaths(),
        ]);
    }
}
