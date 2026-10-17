<?php

namespace App\Deploy\Conversion;

use App\Deploy\Delivery\GitHubMirror;
use App\Enums\ProjectSource;
use App\Models\Project;
use App\Models\Target;
use App\Platform\Git\GitRunner;
use App\Platform\Local\ProjectRoots;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\SshAlias;
use App\Platform\Ssh\SshConfigCompiler;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Which commit a folder deployed in place came from, file by file: the
 * candidate commit is exported in the worker, and rsync compares the server's
 * folder with it by checksum. rsync runs as a dry run *from* the server *into*
 * the export, so it can't change anything on the server, whatever happens.
 */
final class RepositoryComparison
{
    // Commits tried at most: the image tag, the one before the files' time, the branch.
    private const CANDIDATES = 3;

    public function __construct(
        private readonly GitRunner $git,
        private readonly ProjectRoots $roots,
        private readonly GitHubMirror $mirror,
        private readonly SshConfigCompiler $compiler,
        private readonly KnownHosts $knownHosts,
    ) {}

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed> the closest commit and, per item, what differs; or ['error' => …]
     */
    public function compare(Target $target, array $scan): array
    {
        $project = $target->project;

        try {
            $repository = $this->repository($project);
        } catch (Throwable $e) {
            return ['error' => 'deploy.me couldn’t open the repository: '.Str::limit($e->getMessage(), 200)];
        }

        if ($repository === null) {
            return ['error' => 'The repository isn’t on this Mac any more, so the files weren’t compared with it.'];
        }

        $best = null;
        $tried = 0;

        foreach (array_slice($this->candidates($repository, $project, $scan), 0, self::CANDIDATES) as $sha) {
            $tried++;
            $result = $this->against($target, $repository, $sha);

            if (isset($result['error'])) {
                return $result;
            }

            if ($best === null || $result['differs'] < $best['differs']) {
                $best = $result;
            }

            if ($result['differs'] === 0) {
                break;
            }
        }

        return $best !== null ? $best + ['tried' => $tried] : ['error' => "The branch {$project->branch} isn’t in the repository."];
    }

    private function repository(Project $project): ?string
    {
        if ($project->source === ProjectSource::Github) {
            return $this->mirror->sync($project);
        }

        return $this->roots->resolve((string) $project->local_path);
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return list<string> full commit ids, most likely first
     */
    private function candidates(string $repository, Project $project, array $scan): array
    {
        $refs = [];

        if (preg_match('/^[0-9a-f]{7,40}$/', (string) ($scan['image_tag'] ?? '')) === 1) {
            $refs[] = (string) $scan['image_tag'];
        }

        // An upload stamps the files with its time: the last commit before it.
        $newest = collect($scan['items'] ?? [])
            ->reject(fn (array $item) => preg_match('/^\.env(\..*)?$/', $item['name']) === 1 || $item['type'] === 'l')
            ->max('mtime');

        if ($newest > 0) {
            $before = trim($this->git->run($repository, ['log', '-1', '--format=%H', '--before=@'.($newest + 600), $project->branch], timeout: 30)->output());
            if ($before !== '') {
                $refs[] = $before;
            }
        }

        $refs[] = $project->branch;
        $shas = [];

        foreach ($refs as $ref) {
            $sha = $this->git->run($repository, ['rev-parse', '--verify', '--quiet', '--end-of-options', $ref.'^{commit}'], timeout: 30);

            if ($sha->successful() && ($sha = trim($sha->output())) !== '' && ! in_array($sha, $shas, true)) {
                $shas[] = $sha;
            }
        }

        return $shas;
    }

    /**
     * @return array<string, mixed>
     */
    private function against(Target $target, string $repository, string $sha): array
    {
        $export = sys_get_temp_dir().'/deployme-compare-'.$target->id.'-'.Str::lower(Str::random(6));
        $tar = "{$export}.tar";

        try {
            File::ensureDirectoryExists($export, 0700);
            $this->git->mustRun($repository, ['archive', '--format=tar', '-o', $tar, $sha], timeout: 600);
            Process::timeout(600)->run(['tar', '-xf', $tar, '-C', $export])->throw();

            $inRepository = array_values(array_diff(scandir($export) ?: [], ['.', '..']));
            [$items, $error] = $this->rsync($target, $export);

            if ($error !== null) {
                return ['error' => $error];
            }

            foreach ($inRepository as $name) {
                $items[$name] = ($items[$name] ?? ['server_only' => 0, 'differs' => 0, 'repo_only' => 0]) + ['in_repo' => true];
                $items[$name]['in_repo'] = true;
            }

            $differs = array_sum(array_map(fn (array $item) => ($item['in_repo'] ?? false) ? $item['differs'] + $item['repo_only'] : 0, $items));
            [$message, $date] = array_pad(explode("\0", trim($this->git->run($repository, ['log', '-1', '--format=%s%x00%cI', $sha], timeout: 30)->output())), 2, null);

            return [
                'commit' => $sha,
                'message' => $message,
                'date' => $date,
                'exact' => $differs === 0,
                'differs' => $differs,
                'items' => $items,
            ];
        } catch (Throwable $e) {
            return ['error' => 'Comparing with '.substr($sha, 0, 7).' failed: '.Str::limit($e->getMessage(), 200)];
        } finally {
            @unlink($tar);
            File::deleteDirectory($export);
        }
    }

    /**
     * A dry run from the server into the export, by checksum: per item of the
     * folder, files only on the server, files that differ, and files only in
     * the repository.
     *
     * @return array{0: array<string, array<string, int|bool>>, 1: ?string}
     */
    private function rsync(Target $target, string $export): array
    {
        $alias = $target->server->ssh_alias;
        SshAlias::assertValid($alias);

        // The destination is only ever the export in the worker's temp folder.
        if (! str_starts_with($export, sys_get_temp_dir().'/deployme-compare-')) {
            throw new RuntimeException("Won't compare into {$export}.");
        }

        $config = $this->compiler->compile();
        $this->knownHosts->materialize();

        $result = Process::timeout(600)->env(['LC_ALL' => 'C'])->run([
            (string) config('deployme.sync.rsync_binary', 'rsync'),
            '--dry-run',
            '--recursive',
            '--links',
            '--checksum',
            '--delete',
            '--itemize-changes',
            '--out-format=%i %l %n%L',
            '--no-human-readable',
            '--protect-args',
            '--exclude=/.deploy/',
            '--exclude=/releases/',
            '--exclude=/shared/',
            '--exclude=/current',
            '--exclude=/.git/',
            '--rsh='.config('deployme.ssh.binary', 'ssh').' -F '.$config,
            $alias.':'.rtrim($target->path, '/').'/',
            rtrim($export, '/').'/',
        ]);

        // 23 and 24: some files couldn't be read, or vanished: the rest still counts.
        if (! in_array($result->exitCode(), [0, 23, 24], true)) {
            $last = collect(preg_split('/\R/', trim($result->errorOutput())) ?: [])->filter()->last();

            return [[], 'rsync couldn’t compare the folder: '.Str::limit((string) ($last ?? "exit code {$result->exitCode()}"), 200)];
        }

        return [self::tally($result->output()), null];
    }

    /**
     * rsync's itemized lines, counted per item of the folder.
     *
     * @return array<string, array<string, int|bool>>
     */
    public static function tally(string $output): array
    {
        $items = [];
        $count = function (string $path, string $what) use (&$items): void {
            $top = explode('/', $path, 2)[0];
            $items[$top] ??= ['server_only' => 0, 'differs' => 0, 'repo_only' => 0];
            $items[$top][$what]++;
        };

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\*deleting\s+(?:\d+\s+)?(.+)$/', rtrim($line), $match)) {
                if (! str_ends_with($match[1], '/')) {
                    $count(rtrim($match[1], '/'), 'repo_only');
                }

                continue;
            }

            if (! preg_match('/^([<>ch.*])([fdLDS])(\S{9}) \d+ (.+)$/', rtrim($line), $match)) {
                continue;
            }

            [, $what, $type, $attributes, $name] = $match;
            $name = $type === 'L' ? preg_replace('/ -> .*$/', '', $name) : rtrim($name, '/');

            if ($name === '.' || $name === '') {
                continue;
            }

            if (str_contains($attributes, '+')) {
                if ($type !== 'd') {
                    $count($name, 'server_only');
                } elseif (! str_contains($name, '/')) {
                    $items[$name] ??= ['server_only' => 0, 'differs' => 0, 'repo_only' => 0];
                }
            } elseif ($type === 'f' && $what !== '.') {
                $count($name, 'differs');
            }
        }

        return $items;
    }
}
