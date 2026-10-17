<?php

namespace App\Livewire\Projects;

use App\Actions\Projects\ImportProject;
use App\Enums\ProjectSource;
use App\Jobs\PublishRepository;
use App\Models\Credential;
use App\Models\Project;
use App\Platform\Detection\Detection;
use App\Platform\Detection\GitHubInspector;
use App\Platform\Detection\LocalInspector;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;
use App\Platform\Local\ProjectRoots;
use App\Support\Format;
use App\Support\JobStatus;
use App\Support\Names;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Import a project from GitHub (read through the API) or from a local folder:
 * see what's in it, optionally put a local repository on GitHub, choose the
 * deploy settings.
 */
#[Title('Import project')]
class Import extends Component
{
    #[Url]
    public string $from = 'github';

    // From GitHub
    #[Url]
    public string $owner = '';

    public string $filter = '';

    #[Locked]
    public ?string $repository = null;

    // From a local folder
    public string $path = '';

    public bool $browsing = false;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $detection = null;

    #[Locked]
    public ?string $problem = null;

    // Deploy settings
    public string $name = '';

    public string $slug = '';

    public string $branch = 'main';

    public bool $versionable = true;

    public int $keepReleases = 5;

    public bool $dockerized = false;

    public string $stackName = '';

    public string $composeFile = '';

    /** @var list<array{key: string, enabled: bool}> */
    public array $strategies = [];

    // Put a local repository on GitHub
    public string $publishOwner = '';

    public string $publishName = '';

    public bool $publishPrivate = true;

    #[Locked]
    public ?string $publishId = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $publishing = null;

    public function mount(): void
    {
        if (! in_array($this->from, ['github', 'local'], true)) {
            $this->from = 'github';
        }
    }

    public function switchTo(string $from): void
    {
        $this->from = $from === 'local' ? 'local' : 'github';
        $this->reset('detection', 'problem', 'repository', 'publishId', 'publishing');
    }

    // ── From GitHub ─────────────────────────────────────────────────────

    /**
     * Every repository the saved tokens can see, newest first, cached for a
     * few minutes per token.
     *
     * @return array{list: list<array<string, mixed>>, errors: list<string>}
     */
    #[Computed]
    public function githubRepositories(): array
    {
        $imported = Project::query()->active()->where('source', ProjectSource::Github)->get(['github_owner', 'github_repo'])
            ->map(fn (Project $project) => strtolower($project->githubFullName()))
            ->all();

        $repositories = [];
        $errors = [];

        foreach (Credential::query()->where('provider', 'github')->orderBy('id')->get() as $credential) {
            try {
                $list = Cache::remember("deployme:github:repositories:{$credential->id}", 300, fn () => array_map(fn (array $repository) => [
                    'full_name' => (string) $repository['full_name'],
                    'owner' => (string) $repository['owner']['login'],
                    'name' => (string) $repository['name'],
                    'private' => (bool) ($repository['private'] ?? true),
                    'archived' => (bool) ($repository['archived'] ?? false),
                    'language' => $repository['language'] ?? null,
                    'updated_at' => $repository['pushed_at'] ?? $repository['updated_at'] ?? null,
                ], GitHubClient::for($credential)->repositories()));
            } catch (GitHubException $e) {
                $errors[] = "{$credential->login}: {$e->getMessage()}";

                continue;
            }

            foreach ($list as $repository) {
                $repositories[strtolower($repository['full_name'])] ??= $repository + [
                    'credential_id' => $credential->id,
                    'imported' => in_array(strtolower($repository['full_name']), $imported, true),
                ];
            }
        }

        $list = array_values($repositories);
        usort($list, fn (array $a, array $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

        return ['list' => $list, 'errors' => $errors];
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function visibleRepositories(): array
    {
        $needle = Str::lower(trim($this->filter));

        return collect($this->githubRepositories['list'])
            ->reject(fn (array $repository) => $repository['archived'])
            ->filter(fn (array $repository) => $this->owner === '' || strcasecmp($repository['owner'], $this->owner) === 0)
            ->filter(fn (array $repository) => $needle === '' || str_contains(Str::lower($repository['full_name']), $needle))
            ->take(60)
            ->values()
            ->all();
    }

    public function selectRepository(string $fullName, GitHubInspector $inspector): void
    {
        $repository = collect($this->githubRepositories['list'])->firstWhere('full_name', $fullName);

        if ($repository === null || $repository['imported']) {
            return;
        }

        $this->problem = null;
        $credential = Credential::query()->find($repository['credential_id']);

        try {
            $data = $inspector->inspect(GitHubClient::for($credential), $repository['owner'], $repository['name']);
        } catch (GitHubException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->repository = $repository['full_name'];
        $this->useDetection($data, $repository['name']);
    }

    // ── From a local folder ─────────────────────────────────────────────

    public function inspect(): void
    {
        $this->reset('detection', 'problem', 'publishId', 'publishing');
        $roots = app(ProjectRoots::class);
        $path = $roots->resolve($this->path);

        if ($path === null) {
            $this->problem = 'Pick a folder inside '.collect($roots->roots())->map(fn (array $root) => Format::homePath($root['path']))->implode(', ').'.';

            return;
        }

        if (Project::query()->active()->where('local_path', $path)->exists()) {
            $this->problem = Format::homePath($path).' is already a project.';

            return;
        }

        $this->path = $path;
        $this->useDetection(app(LocalInspector::class)->inspect($path), basename($path));
    }

    public function usePath(): void
    {
        $this->browsing = false;
        $this->inspect();
    }

    /**
     * Creates the GitHub repository and pushes (no GitHub remote yet), or
     * pushes to the GitHub remote. Runs in the worker; the page polls.
     */
    public function publish(): void
    {
        $detection = $this->detection !== null ? new Detection($this->detection) : null;

        if ($this->from !== 'local' || $detection?->git() === null || $this->publishId !== null) {
            return;
        }

        $this->problem = null;
        $github = $detection->github();
        $credentialId = $github !== null
            ? Credential::forOwner($github['owner'])?->id
            : (Credential::ownerChoices()[$this->publishOwner] ?? null);

        if ($credentialId === null) {
            $this->problem = 'None of your GitHub tokens reaches '.($github['owner'] ?? $this->publishOwner).'. Add one in Settings.';

            return;
        }

        if ($github === null && ! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $this->publishName)) {
            $this->problem = 'Repository names use letters, digits, dots, dashes and underscores.';

            return;
        }

        $this->publishId = (string) Str::uuid();
        JobStatus::start($this->publishId, 'Waiting for the worker');

        PublishRepository::dispatch(
            $this->publishId,
            // The folder that was inspected: the path field may say something else by now.
            (string) ($detection->data['path'] ?? ''),
            $credentialId,
            owner: $github === null ? $this->publishOwner : null,
            name: $github === null ? $this->publishName : null,
            private: $this->publishPrivate,
            remote: $github['remote'] ?? null,
        );

        $this->publishing = JobStatus::get($this->publishId);
    }

    public function checkPublish(): void
    {
        if ($this->publishId === null) {
            return;
        }

        $this->publishing = JobStatus::get($this->publishId);

        if (($this->publishing['state'] ?? 'running') === 'running') {
            return;
        }

        $this->publishId = null;

        if ($this->publishing['state'] === 'done') {
            // The folder now has a GitHub remote (or is in sync with it): look again.
            $data = app(LocalInspector::class)->inspect((string) ($this->detection['path'] ?? ''));
            $this->detection = $data;
            $this->strategies = (new Detection($data))->suggestedStrategies(localFolder: true);
        }
    }

    // ── Import ──────────────────────────────────────────────────────────

    public function import(ImportProject $import): void
    {
        if ($this->detection === null) {
            return;
        }

        $detection = new Detection($this->detection);
        $source = $this->from === 'local' ? ProjectSource::Local : ProjectSource::Github;

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Names::isFolder((string) $value)) {
                    $fail('Use lowercase letters, digits, dots, dashes and underscores.');
                }
            }, Rule::unique('projects', 'slug')],
            'branch' => ['required', 'string', 'max:200', 'regex:/^(?!-)[A-Za-z0-9._\/-]+$/'],
            'keepReleases' => ['required', 'integer', 'between:1,10'],
            'stackName' => [Rule::requiredIf($this->dockerized), 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if ($this->dockerized && ! Names::isStack((string) $value)) {
                    $fail('Compose project names use lowercase letters, digits, dashes and underscores.');
                }
            }],
            'composeFile' => [Rule::requiredIf($this->dockerized), 'nullable', Rule::in(array_column($detection->composeFiles(), 'file'))],
        ], [], ['keepReleases' => 'releases to keep', 'stackName' => 'stack name', 'composeFile' => 'compose file']);

        $duplicate = $source === ProjectSource::Local
            ? Project::query()->active()->where('local_path', $detection->data['path'] ?? null)->exists()
            : Project::query()->active()->where('source', ProjectSource::Github)
                ->whereRaw('lower(github_owner) = ? and lower(github_repo) = ?', [strtolower((string) $detection->github()['owner']), strtolower((string) $detection->github()['repo'])])
                ->exists();

        if ($duplicate) {
            $this->problem = 'This project is already imported.';

            return;
        }

        $project = $import($detection, $source, [
            'name' => $this->name,
            'slug' => $this->slug,
            'branch' => $this->branch,
            'versionable' => $this->versionable,
            'keep_releases' => $this->keepReleases,
            'dockerized' => $this->dockerized,
            'stack_name' => $this->stackName !== '' ? $this->stackName : null,
            'compose_file' => $this->composeFile !== '' ? $this->composeFile : null,
            'strategies' => Project::normalizeStrategies($this->strategies),
        ]);

        $this->redirectRoute('projects.show', $project, navigate: true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function useDetection(array $data, string $name): void
    {
        $detection = new Detection($data);

        $this->detection = $data;
        $this->name = $name;
        $this->slug = Project::uniqueSlug($name);
        $this->branch = $detection->git()['branch'] ?? $detection->github()['branch'] ?? 'main';
        $this->versionable = true;
        $this->keepReleases = 5;
        $this->dockerized = $detection->primaryCompose() !== null;
        $this->composeFile = $detection->primaryCompose()['file'] ?? '';
        $this->stackName = $detection->suggestedStackName($this->slug);
        $this->strategies = $detection->suggestedStrategies(localFolder: $this->from === 'local');

        $this->publishOwner = (string) array_key_first(Credential::ownerChoices());
        $this->publishName = Names::folder($name);
    }

    public function render(ProjectRoots $roots): View
    {
        return view('livewire.projects.import', [
            'detected' => $this->detection !== null ? new Detection($this->detection) : null,
            'owners' => array_keys(Credential::ownerChoices()),
            'roots' => $roots->roots(),
        ]);
    }
}
