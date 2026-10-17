<?php

namespace App\Livewire\Projects;

use App\Actions\Projects\ImportEnvFromServer;
use App\Enums\EnvFileRole;
use App\Enums\ProjectSource;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Models\Credential;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Target;
use App\Platform\Detection\LocalFiles;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;
use App\Platform\Local\ProjectRoots;
use App\Platform\Ssh\Exceptions\SshException;
use App\Support\DotEnv;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A project's env files: encrypted at rest, secrets masked until you confirm
 * it's you, each save kept as a version. Values only reach the page once
 * you've confirmed.
 */
class Environment extends Component
{
    use ConfirmsIdentity;

    #[Locked]
    public int $projectId;

    #[Url(as: 'file')]
    public ?int $fileId = null;

    #[Locked]
    public bool $revealed = false;

    #[Locked]
    public bool $editing = false;

    public string $content = '';

    public bool $adding = false;

    public string $newPath = '.env';

    public string $newRole = 'app';

    public string $template = '';

    // The target an import reads from; the only one, unless the project has several.
    public ?int $targetId = null;

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;

        if ($this->fileId === null || ! $this->project->envFiles()->whereKey($this->fileId)->exists()) {
            $this->fileId = $this->project->envFiles()->value('id');
        }

        $this->targetId = $this->targets->keys()->first();
    }

    /**
     * The deployed folders an import can read: a target nobody has deployed to
     * yet holds no env file worth taking.
     *
     * @return Collection<int, Target>
     */
    #[Computed]
    public function targets(): Collection
    {
        return $this->project->targets()->active()->whereNotNull('bootstrapped_at')
            ->with('server')->get()->keyBy('id');
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    #[Computed]
    public function file(): ?ProjectEnvFile
    {
        return $this->fileId !== null ? $this->project->envFiles()->find($this->fileId) : null;
    }

    public function select(int $id): void
    {
        $this->reset('editing', 'content', 'adding', 'notice', 'problem');
        $this->fileId = $this->project->envFiles()->whereKey($id)->value('id');
        unset($this->file);
    }

    public function reveal(): void
    {
        if ($this->confirmIdentity($this->returnUrl())) {
            $this->revealed = true;
        }
    }

    public function edit(): void
    {
        if ($this->file === null || ! $this->confirmIdentity($this->returnUrl())) {
            return;
        }

        $this->reset('notice', 'problem');
        $this->revealed = true;
        $this->editing = true;
        $this->content = $this->file->content;
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'content');
    }

    public function save(): void
    {
        if (! $this->editing || $this->file === null || ! $this->confirmIdentity($this->returnUrl())) {
            return;
        }

        $this->validate(['content' => ['string', 'max:65535']]);

        $file = $this->file;
        $saved = $file->revise(str_replace("\r\n", "\n", $this->content));

        $this->reset('editing', 'content');
        unset($this->file);
        $this->notice = $saved ? "Saved {$file->path} as v{$file->version}." : 'Nothing changed.';
    }

    public function startAdding(): void
    {
        $this->reset('notice', 'problem', 'editing', 'content', 'template');
        $this->adding = true;
        $this->newPath = $this->project->envFiles()->where('path', '.env')->exists() ? '' : '.env';
        $this->newRole = $this->project->dockerized ? EnvFileRole::Compose->value : EnvFileRole::App->value;
    }

    public function addFile(): void
    {
        $project = $this->project;

        $this->validate([
            'newPath' => ['required', 'string', 'max:200', 'regex:/^(?!\/)(?!.*\.\.)[A-Za-z0-9._\/-]+$/',
                Rule::unique('project_env_files', 'path')->where('project_id', $project->id)],
            'newRole' => ['required', Rule::enum(EnvFileRole::class)],
            'template' => ['nullable', 'string', Rule::in(array_column($project->detected()->envTemplates(), 'path'))],
        ], ['newPath.regex' => 'A path inside the deploy folder, like .env or src/api/.env.'], ['newPath' => 'path']);

        $template = $this->template;
        $content = $template !== '' ? $this->readTemplate($project, $template) : '';

        if ($content === null) {
            $this->problem = "Couldn’t read {$template}. The file is empty; edit it to add keys.";
            $content = '';
        }

        $file = $project->envFiles()->create(['path' => $this->newPath, 'role' => $this->newRole, ...ProjectEnvFile::contentAttributes($content)]);

        $this->reset('adding', 'template');
        $this->fileId = $file->id;
        unset($this->file);
        $this->notice = "Added {$file->path}".($template !== '' && $content !== '' ? " from {$template}" : '').'.';
    }

    /**
     * Takes the env files the project already runs with on a server. A project
     * deployed before deploy.me existed keeps them only there, and until
     * deploy.me has them it can't back its database up before it migrates.
     */
    public function importFromServer(ImportEnvFromServer $import): void
    {
        $this->reset('notice', 'problem', 'editing', 'content', 'adding');
        $target = $this->targets->get((int) $this->targetId);

        if ($target === null) {
            $this->problem = 'Deploy this project to a server first — an import reads the files from the folder it runs in.';

            return;
        }

        // The files are secrets: taking them is as sensitive as revealing one.
        if (! $this->confirmIdentity($this->returnUrl())) {
            return;
        }

        try {
            $result = $import($target);
        } catch (SshException $e) {
            $this->problem = "Couldn’t read {$target->path} on {$target->server->name}: {$e->userMessage()}";

            return;
        }

        unset($this->file);
        $imported = $result['imported'];
        $this->fileId = $imported === []
            ? $this->fileId
            : $this->project->envFiles()->where('path', $imported[0])->value('id');

        $this->notice = match (true) {
            $imported !== [] => 'Imported '.implode(', ', $imported)." from {$target->server->name}.",
            $result['unchanged'] !== [] => 'Already up to date with '.$target->server->name.': '.implode(', ', $result['unchanged']).'.',
            default => "Found no env file in {$target->path} on {$target->server->name}.",
        };
    }

    public function deleteFile(): void
    {
        if ($this->file === null || ! $this->confirmIdentity($this->returnUrl())) {
            return;
        }

        $path = $this->file->path;
        $this->file->delete();

        $this->reset('editing', 'content');
        $this->fileId = $this->project->envFiles()->value('id');
        unset($this->file);
        $this->notice = "Removed {$path}.";
    }

    /**
     * An env template's contents, from the folder or from GitHub.
     */
    private function readTemplate(Project $project, string $path): ?string
    {
        if ($project->source === ProjectSource::Local) {
            $root = app(ProjectRoots::class)->resolve((string) $project->local_path);

            return $root !== null ? (new LocalFiles($root))->read($path) : null;
        }

        $credential = $project->credential ?? Credential::forOwner((string) $project->github_owner);

        if ($credential === null) {
            return null;
        }

        try {
            return GitHubClient::for($credential)->raw((string) $project->github_owner, (string) $project->github_repo, $path, $project->branch);
        } catch (GitHubException) {
            return null;
        }
    }

    private function returnUrl(): string
    {
        return route('projects.show', ['project' => $this->project, 'tab' => 'environment', 'file' => $this->fileId]);
    }

    public function render(): View
    {
        $file = $this->file;

        // Revealing lasts as long as the confirmation it took: a tab left open
        // past it shows the values masked again, whichever file it opens.
        if ($this->revealed && ! $this->editing && ! $this->identityConfirmed()) {
            $this->revealed = false;
        }

        return view('livewire.projects.environment', [
            'files' => $this->project->envFiles()->get(),
            'file' => $file,
            // Rendered masked unless revealed; secrets never go into the page's state.
            'keys' => $file !== null && ! $this->editing ? DotEnv::parse($file->content) : [],
            'versions' => $file?->versions()->count() ?? 0,
            'templates' => $this->project->detected()->envTemplates(),
            'roles' => EnvFileRole::cases(),
            'targets' => $this->targets,
        ]);
    }
}
