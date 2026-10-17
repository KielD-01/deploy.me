<?php

namespace App\Livewire\Projects;

use App\Actions\Deployments\StartDeployment;
use App\Actions\Projects\AssignProject;
use App\Actions\Targets\ChangeTargetFolder;
use App\Deploy\RemoteLayout;
use App\Enums\AuditAction;
use App\Enums\DeploymentKind;
use App\Enums\ServerStatus;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Livewire\Concerns\StartsRuns;
use App\Models\Project;
use App\Models\Server;
use App\Models\Target;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The servers a project runs on, what's live on each, and assigning it to
 * another one.
 */
class Targets extends Component
{
    use ConfirmsIdentity, StartsRuns;

    #[Locked]
    public int $projectId;

    public bool $assigning = false;

    public $serverId = null;

    public string $baseDir = '';

    // Where it deploys: {baseDir}/{slug} ("inside"), or `folder` itself ("exact").
    public string $folderMode = 'inside';

    public string $folder = '';

    // Deploy the branch once assigned; the deploy creates the folders itself.
    public bool $deployAfter = true;

    // Without a deploy: create the folders and upload the env files now.
    public bool $prepare = true;

    public bool $browsing = false;

    // Which folder the browser fills in: the assign form's, or a move's.
    public string $browseFor = 'assign';

    // Changing a target's folder: which target, and the new folder.
    #[Locked]
    public ?int $moving = null;

    public string $moveMode = 'exact';

    public string $moveBase = '';

    public string $moveFolder = '';

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    // Runs update the table as they go.
    #[On('echo-private:projects.{projectId},.updated')]
    public function refreshTargets(): void {}

    // So do health checks that come back different.
    #[On('echo-private:projects.{projectId},.health.updated')]
    public function refreshHealth(): void {}

    // And reads of a folder, which say whether it needs converting.
    #[On('echo-private:projects.{projectId},.folder.read')]
    public function refreshFolder(): void {}

    public function startAssigning(): void
    {
        $this->reset('notice', 'problem');
        $this->assigning = true;

        $first = collect($this->serverChoices())->first(fn (array $choice) => ! $choice['assigned']);
        if ($first !== null) {
            $this->pickServer($first['id']);
        }
    }

    public function pickServer(int $id): void
    {
        $server = Server::query()->active()->find($id);

        if ($server !== null) {
            $this->serverId = $server->id;
            $this->baseDir = $server->default_deploy_path ?: '/opt/srv';
            $this->folder = Target::pathFor($this->baseDir, $this->project->slug);
            $this->resetValidation();
        }
    }

    public function pickFolderMode(string $mode): void
    {
        $this->folderMode = $mode === 'exact' ? 'exact' : 'inside';
        $this->resetValidation();
    }

    public function browseFolder(string $for = 'assign'): void
    {
        $this->browseFor = $for === 'move' ? 'move' : 'assign';
        $this->browsing = true;
    }

    public function usePath(): void
    {
        $this->browsing = false;
    }

    public function startMoving(int $id): void
    {
        $this->reset('notice', 'problem');
        $target = $this->project->targets()->active()->with(['project', 'server'])->findOrFail($id);
        $this->resetValidation();
        $this->moving = $target->id;
        $this->moveMode = 'exact';
        $this->moveBase = dirname($target->path);
        $this->moveFolder = $target->path;
    }

    public function stopMoving(): void
    {
        $this->reset('moving', 'browsing');
        $this->resetValidation();
    }

    public function pickMoveMode(string $mode): void
    {
        $this->moveMode = $mode === 'inside' ? 'inside' : 'exact';
        $this->resetValidation();
    }

    public function changeFolder(ChangeTargetFolder $change): void
    {
        $target = $this->project->targets()->active()->find((int) $this->moving);

        if ($target === null) {
            $this->moving = null;

            return;
        }

        $exact = $this->moveMode === 'exact';
        $from = $target->path;

        try {
            $change($target, $exact ? $this->moveFolder : $this->moveBase, $exact);
        } catch (ValidationException $e) {
            $this->addError('moveFolder', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->reset('moving', 'browsing');
        $this->notice = "{$target->server->name} now deploys to {$target->path}. Nothing on the server changed; whatever was prepared in {$from} stays there.";
    }

    public function assign(AssignProject $assign, StartDeployment $start): void
    {
        $server = Server::query()->active()->find((int) $this->serverId);

        if ($server === null) {
            $this->addError('serverId', 'Pick a server.');

            return;
        }

        $exact = $this->folderMode === 'exact';
        $target = $assign($this->project, $server, $exact ? $this->folder : $this->baseDir, $exact);
        $this->reset('assigning', 'browsing', 'folderMode', 'folder');

        if ($this->deployAfter) {
            $this->startRun($start, $target, DeploymentKind::Deploy);

            return;
        }

        if ($this->prepare) {
            $this->startRun($start, $target, DeploymentKind::Bootstrap);

            return;
        }

        $this->notice = "Assigned to {$server->name}. It deploys to {$target->path}.";
    }

    public function prepareTarget(int $id, StartDeployment $start): void
    {
        $this->startRun($start, $this->project->targets()->active()->findOrFail($id), DeploymentKind::Bootstrap);
    }

    /**
     * Takes what an earlier deployment left in an in-place folder as the
     * live release, without changing anything there.
     */
    public function adoptTarget(int $id, StartDeployment $start): void
    {
        $this->startRun($start, $this->project->targets()->active()->findOrFail($id), DeploymentKind::Adopt);
    }

    /**
     * Removes a deploy lock nothing will give back, whoever holds it. It's a
     * run of its own, so who broke it and when stays on record, and it asks
     * for a passkey first.
     */
    public function breakLock(int $id, StartDeployment $start): void
    {
        $this->reset('notice', 'problem');
        $target = $this->project->targets()->active()->findOrFail($id);

        if ($target->activeDeployment()->exists()) {
            $this->problem = 'A run is still going on this target. Wait for it, or cancel it first.';

            return;
        }

        if (! $this->confirmIdentity(route('projects.show', ['project' => $this->project, 'tab' => 'targets']))) {
            return;
        }

        $this->startRun($start, $target, DeploymentKind::Unlock);
    }

    public function remove(int $id): void
    {
        $this->reset('notice', 'problem');
        $target = $this->project->targets()->active()->with('server')->findOrFail($id);

        if ($target->activeDeployment()->exists()) {
            $this->problem = 'A run is still going on this target. Wait for it, or cancel it first.';

            return;
        }

        $target->update(['archived_at' => now()]);
        Audit::record(AuditAction::TargetRemoved, [
            'subject' => "{$this->project->name} on {$target->server->name} · {$target->path}",
            'server_id' => $target->server_id,
            'project_id' => $target->project_id,
            'detail' => 'nothing on the server changed',
        ]);
        $this->notice = "Removed {$target->server->name}. Nothing on the server changed.";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serverChoices(): array
    {
        $assigned = $this->project->targets()->active()->pluck('server_id')->all();

        return Server::query()->active()->with('latestSnapshot')->orderBy('name')->get()
            ->map(fn (Server $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'env' => $server->env_tag,
                'default' => $server->default_deploy_path,
                'free' => $server->latestSnapshot?->diskFor($server->default_deploy_path ?: '/')['available'] ?? null,
                'offline' => $server->status === ServerStatus::Offline,
                'assigned' => in_array($server->id, $assigned, true),
            ])
            ->all();
    }

    public function render(): View
    {
        $project = $this->project;
        $server = $this->serverId !== null ? Server::query()->find((int) $this->serverId) : null;
        $chosen = $this->folderMode === 'exact' ? rtrim(trim($this->folder), '/') : (str_starts_with(trim($this->baseDir), '/') ? Target::pathFor(trim($this->baseDir), $project->slug) : '');
        $path = $server !== null && str_starts_with($chosen, '/') ? $chosen : null;
        $moving = $this->moving !== null ? $project->targets()->active()->with(['server', 'currentRelease', 'project'])->find($this->moving) : null;
        $movePath = $this->moveMode === 'exact' ? rtrim(trim($this->moveFolder), '/') : (str_starts_with(trim($this->moveBase), '/') ? Target::pathFor(trim($this->moveBase), $project->slug) : '');

        return view('livewire.projects.targets', [
            'targets' => $project->targets()->active()->with(['project', 'server', 'latestDeployment', 'activeDeployment', 'currentRelease'])->orderBy('id')->get(),
            'choices' => $this->assigning ? $this->serverChoices() : [],
            'server' => $server,
            'path' => $path,
            'preview' => $path !== null ? (new RemoteLayout($path, $project->versionable))->preview($project->keep_releases, $project->envFiles()->pluck('path')->all()) : [],
            'envCount' => $project->envFiles()->count(),
            'movingTarget' => $moving,
            'movePath' => str_starts_with($movePath, '/') ? $movePath : null,
            'browseServer' => $this->browseFor === 'move' ? $moving?->server : $server,
        ]);
    }
}
