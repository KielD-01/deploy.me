<?php

namespace App\Deploy\Actions;

use App\Enums\ProjectSource;
use App\Models\Credential;
use App\Models\Project;
use App\Models\ProjectAction;
use App\Platform\Detection\FileSource;
use App\Platform\Git\GitRunner;
use App\Platform\Git\LocalRepository;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;
use App\Platform\Local\ProjectRoots;
use App\Support\Format;
use RuntimeException;
use Throwable;

/**
 * The actions a repository keeps in .deployme/actions/*.yml (or .yaml), read
 * at the head of the project's branch: a local repository through git (never
 * the working tree, which may hold what isn't committed), a GitHub one
 * through the API. Each file becomes an action named after it, with its
 * errors when it doesn't pass; an action whose file is gone goes too.
 */
final class RepoActions
{
    private const PATTERN = '~^\.deployme/actions/([^/]+)\.ya?ml$~';

    // A file bigger than this isn't an action.
    private const MAX_BYTES = 65536;

    public function __construct(
        private readonly GitRunner $git,
        private readonly ProjectRoots $roots,
    ) {}

    /**
     * Reads the files at the branch head and stores what they say.
     *
     * @return array{actions: int, errors: int, removed: int, commit: ?string}
     *
     * @throws RuntimeException when the repository can't be read
     */
    public function reload(Project $project): array
    {
        [$files, $commit] = $project->source === ProjectSource::Local ? $this->local($project) : $this->github($project);

        return $this->store($project, $files, $commit !== '' ? $commit : null);
    }

    /**
     * After an import or a fresh detection: a GitHub inspection brought the
     * files along (GitHubInspector reads them with the rest), a local
     * project's are read from git now. Never the reason an import fails.
     *
     * @param  array<string, mixed>  $data  the inspection
     * @return array{actions: int, errors: int, removed: int, commit: ?string}|null
     */
    public function afterDetection(Project $project, array $data): ?array
    {
        try {
            if ($project->source === ProjectSource::Github && is_array($data['action_files'] ?? null)) {
                return $this->store($project, $data['action_files'], $data['github']['head']['sha'] ?? null);
            }

            return $project->source === ProjectSource::Local ? $this->reload($project) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * "Read 4 actions from .deployme/actions at 9fabf06 (1 with errors)."
     *
     * @param  array{actions: int, errors: int, removed: int, commit: ?string}  $result
     */
    public static function summary(array $result): string
    {
        if ($result['actions'] === 0) {
            return 'The repository has no actions in '.ProjectAction::FOLDER.($result['removed'] > 0 ? "; removed {$result['removed']} whose file is gone." : '.');
        }

        return 'Read '.$result['actions'].' '.($result['actions'] === 1 ? 'action' : 'actions').' from '.ProjectAction::FOLDER
            .($result['commit'] !== null ? ' at '.substr($result['commit'], 0, 7) : '')
            .($result['errors'] > 0 ? " ({$result['errors']} with errors)" : '')
            .($result['removed'] > 0 ? "; removed {$result['removed']} whose file is gone" : '').'.';
    }

    /**
     * The action files of a project's file listing, with their contents.
     *
     * @return array<string, ?string> path => contents
     */
    public static function files(FileSource $source): array
    {
        $paths = array_values(array_filter($source->files(), fn (string $path) => preg_match(self::PATTERN, $path) === 1));
        $source->prefetch($paths);

        $files = [];
        foreach ($paths as $path) {
            $files[$path] = $source->read($path);
        }

        return $files;
    }

    /**
     * @param  array<string, ?string>  $files  path => contents (null: unreadable)
     * @return array{actions: int, errors: int, removed: int, commit: ?string}
     */
    public function store(Project $project, array $files, ?string $commit): array
    {
        $seen = [];
        $errors = 0;
        ksort($files);

        foreach ($files as $path => $contents) {
            if (preg_match(self::PATTERN, (string) $path, $match) !== 1) {
                continue;
            }

            $key = $match[1];
            $problems = [];

            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key) !== 1) {
                $problems[] = 'The file’s name is the action’s key in the shell and for agents: lowercase letters, digits, - and _.';
                $key = ProjectAction::keyFor($key);
            }

            if (isset($seen[$key])) {
                continue;
            }

            $definition = null;
            if ($contents === null || strlen($contents) > self::MAX_BYTES) {
                $problems[] = 'The file couldn’t be read, or it’s over 64 KB.';
            } elseif ($problems === []) {
                [$definition, $problems] = ActionParser::yaml($contents);
                $problems = [...$problems, ...($definition?->problems($project) ?? [])];
            }

            $existing = ProjectAction::query()->where('project_id', $project->id)->where('key', $key)->first();

            if ($existing !== null && ! $existing->fromRepo()) {
                $problems = ["An action made here is called {$key} already. Rename the file, or that action."];
                $definition = null;
                $existing = null;
                $key = "{$key}-repo";
            }

            $data = $problems === [] ? $definition?->data : null;
            $attributes = [
                'source' => ProjectAction::REPO,
                'file' => (string) $path,
                'name' => $definition?->name() ?? $key,
                'definition' => $data,
                'errors' => $problems !== [] ? $problems : null,
                'commit_sha' => $commit,
                'loaded_at' => now(),
            ];

            // A changed schedule starts counting from now, not from the old one's last time.
            if ($existing === null || ($existing->definition['schedule'] ?? null) !== ($data['schedule'] ?? null)) {
                $attributes['scheduled_through'] = now();
            }

            $project->actions()->updateOrCreate(['key' => $key], $attributes);
            $seen[$key] = true;
            $errors += $problems !== [] ? 1 : 0;
        }

        $removed = $project->actions()->where('source', ProjectAction::REPO)->whereNotIn('key', array_keys($seen) ?: [''])->delete();

        return ['actions' => count($seen), 'errors' => $errors, 'removed' => (int) $removed, 'commit' => $commit];
    }

    /**
     * @return array{0: array<string, ?string>, 1: string}
     */
    private function local(Project $project): array
    {
        $path = $this->roots->resolve((string) $project->local_path);

        if ($path === null) {
            throw new RuntimeException(Format::homePath($project->local_path).' isn’t there any more.');
        }

        if (! LocalRepository::isRepository($path)) {
            return [[], ''];
        }

        $read = function (array $args) use ($path): ?string {
            $result = $this->git->run($path, $args, timeout: 30);

            return $result->successful() ? $result->output() : null;
        };

        $commit = trim((string) $read(['rev-parse', '--verify', '--quiet', "refs/heads/{$project->branch}^{commit}"]));

        if ($commit === '') {
            throw new RuntimeException("The repository has no branch {$project->branch}.");
        }

        $files = [];
        foreach (preg_split('/\R/', trim((string) $read(['ls-tree', '--name-only', $commit, '--', ProjectAction::FOLDER.'/']))) ?: [] as $file) {
            if (preg_match(self::PATTERN, $file) === 1) {
                $files[$file] = $read(['cat-file', 'blob', "{$commit}:{$file}"]);
            }
        }

        return [$files, $commit];
    }

    /**
     * @return array{0: array<string, ?string>, 1: string}
     */
    private function github(Project $project): array
    {
        /** @var Credential|null $credential */
        $credential = $project->credential ?? Credential::forOwner((string) $project->github_owner);

        if ($credential === null || ! $project->hasGitHub()) {
            throw new RuntimeException("Add a GitHub token that reaches {$project->github_owner} first.");
        }

        $github = GitHubClient::for($credential);
        $owner = (string) $project->github_owner;
        $repo = (string) $project->github_repo;

        try {
            $commit = $github->branchHead($owner, $repo, $project->branch) ?? throw new RuntimeException("{$project->githubFullName()} has no branch {$project->branch}.");
            $paths = array_values(array_filter(
                array_column(array_filter($github->tree($owner, $repo, $commit)['tree'], fn (array $node) => $node['type'] === 'blob'), 'path'),
                fn (string $path) => preg_match(self::PATTERN, $path) === 1,
            ));

            return [$github->rawMany($owner, $repo, $paths, $commit), $commit];
        } catch (GitHubException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }
    }
}
