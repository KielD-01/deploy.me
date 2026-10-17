<?php

namespace App\Livewire\Servers;

use App\Actions\Deployments\StartDeployment;
use App\Actions\Projects\AssignProject;
use App\Actions\Servers\RecordProbe;
use App\Deploy\DeploymentBusy;
use App\Enums\AuditAction;
use App\Enums\DeploymentKind;
use App\Enums\EnvTag;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Server;
use App\Models\SshHostKey;
use App\Models\Target;
use App\Models\TargetHealthCheck;
use App\Platform\Remote\RemoteFs;
use App\Platform\Ssh\Exceptions\SshException;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class Show extends Component
{
    // How many recent health checks the Checks tab lists.
    public const CHECKS = 50;

    public Server $server;

    #[Url]
    public string $tab = 'overview';

    // An earlier probe whose busiest processes the Overview shows instead of the latest's.
    #[Url(as: 'probe')]
    public string $processesAt = '';

    public bool $browsing = false;

    public string $browsePath = '';

    public bool $choosingPath = false;

    public string $name = '';

    public string $envTag = '';

    public string $defaultPath = '';

    public int $probeInterval = 300;

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(Server $server): void
    {
        abort_if($server->archived_at !== null, 404);

        $this->server = $server;
        $this->fillSettings();
    }

    // Probes from the scheduler refresh the page as they land.
    #[On('echo-private:servers.{server.id},.probed')]
    public function refreshServer(): void
    {
        $this->server->refresh();
    }

    // Keeps the Folders tab's count current as mappings come and go.
    #[On('folder-mappings-changed')]
    public function refreshFolderCount(): void {}

    public function probeNow(RecordProbe $record): void
    {
        $record($this->server);
        $this->server->refresh();
        unset($this->adoptable);
    }

    /**
     * The stacks on this server deploy.me could take over: one it has a target
     * for but no release of, and one whose folder a project of the same name
     * would deploy into. A project deploys to {base folder}/{slug}, so only a
     * project whose slug is the folder's name can adopt an unassigned stack.
     *
     * @return array<string, array{folder: string, target: ?Target, project: Project}>
     */
    #[Computed]
    public function adoptable(): array
    {
        $targets = $this->server->targets()->active()->with('project')->get();
        $rows = [];

        foreach ((array) ($this->server->snapshots()->latest('captured_at')->first()?->stacks ?? []) as $stack) {
            $file = explode(',', (string) ($stack['ConfigFiles'] ?? ''))[0];
            $name = (string) ($stack['Name'] ?? '');

            if ($file === '' || $name === '' || $this->server->isReserved(dirname($file))) {
                continue;
            }

            $target = $targets->first(fn (Target $target) => str_starts_with($file, rtrim($target->path, '/').'/'));

            if ($target !== null) {
                if (! $target->project->versionable && $target->current_release_id === null) {
                    $rows[$name] = ['folder' => $target->path, 'target' => $target, 'project' => $target->project];
                }

                continue;
            }

            $folder = rtrim(dirname($file), '/');
            $project = Project::query()->active()
                ->where('versionable', false)
                ->where('slug', basename($folder))
                ->whereNotIn('id', $targets->pluck('project_id')->all())
                ->first();

            if ($project !== null) {
                $rows[$name] = ['folder' => $folder, 'target' => null, 'project' => $project];
            }
        }

        return $rows;
    }

    /**
     * Takes over a stack that already runs here: the project it belongs to
     * records what's in the folder as its live release. Nothing on the server
     * changes, and a project that isn't on this server yet is assigned first.
     */
    public function adoptStack(string $name, AssignProject $assign, StartDeployment $start): void
    {
        $this->reset('notice', 'problem');
        $row = $this->adoptable()[$name] ?? null;

        if ($row === null) {
            $this->problem = "{$name} can’t be adopted any more. Probe the server to see what runs there now.";

            return;
        }

        try {
            $target = $row['target'] ?? $assign($row['project'], $this->server, dirname($row['folder']));
            $deployment = $start($target, DeploymentKind::Adopt, auth()->user());
        } catch (ValidationException $e) {
            $this->problem = (string) collect($e->errors())->flatten()->first();

            return;
        } catch (DeploymentBusy $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->redirectRoute('deployments.show', $deployment, navigate: true);
    }

    public function saveSettings(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'envTag' => ['required', Rule::enum(EnvTag::class)],
            'defaultPath' => ['required', 'string', 'starts_with:/', 'max:255'],
            'probeInterval' => ['required', Rule::in(array_keys(Create::INTERVALS))],
        ]);

        if ($this->server->isReserved($this->defaultPath)) {
            $this->addError('defaultPath', "{$this->defaultPath} is reserved for the shared infrastructure.");

            return;
        }

        $this->server->update([
            'name' => $this->name,
            'env_tag' => $this->envTag,
            'default_deploy_path' => rtrim($this->defaultPath, '/') ?: '/',
            'probe_interval' => $this->probeInterval,
            'next_probe_at' => now()->addSeconds($this->probeInterval),
        ]);

        $this->fillSettings();
        $this->notice = 'Settings saved.';
    }

    public function usePath(): void
    {
        $this->choosingPath = false;
    }

    // The folder browser opens where the page tells it to: a folder it picks
    // for itself while mounting doesn't flow back up to the page.
    public function browse(RemoteFs $fs): void
    {
        $this->browsePath = $this->nearestFolder($fs, $this->browsePath ?: ($this->server->default_deploy_path ?: '/opt'));
        $this->browsing = true;
    }

    public function choosePath(RemoteFs $fs): void
    {
        $this->defaultPath = $this->nearestFolder($fs, trim($this->defaultPath) ?: '/opt');
        $this->choosingPath = true;
    }

    private function nearestFolder(RemoteFs $fs, string $path): string
    {
        if (! str_starts_with($path, '/')) {
            return $path;
        }

        try {
            return $fs->nearestFolder($this->server->ssh_alias, $path);
        } catch (SshException|InvalidArgumentException) {
            // The browser says what's wrong when it lists the folder.
            return $path;
        }
    }

    public function archive(): void
    {
        $this->server->update(['archived_at' => now()]);
        Audit::record(AuditAction::ServerRemoved, [
            'subject' => $this->server->name.' ('.$this->server->ssh_alias.')',
            'server_id' => $this->server->id,
            'detail' => 'nothing on the server changed',
        ]);

        $this->redirectRoute('servers.index', navigate: true);
    }

    private function fillSettings(): void
    {
        $this->name = $this->server->name;
        $this->envTag = $this->server->env_tag->value;
        $this->defaultPath = $this->server->default_deploy_path ?? '';
        $this->probeInterval = $this->server->probe_interval;
    }

    public function render(): View
    {
        $server = $this->server;
        $latest = $server->snapshots()->latest('captured_at')->first();
        $history = $server->snapshots()
            ->where('captured_at', '>=', now()->subDay())
            ->orderBy('captured_at')
            ->get(['captured_at', 'cpu_usage', 'mem_total', 'mem_available']);

        // The busiest processes: the latest probe's lists, or the earlier probe's picked.
        $withProcesses = fn () => $server->snapshots()->whereNotNull('processes');
        $processes = ($this->processesAt !== '' ? $withProcesses()->find((int) $this->processesAt) : null)
            ?? $withProcesses()->latest('captured_at')->first();

        return view('livewire.servers.show', [
            'latest' => $latest,
            'disk' => $latest?->diskFor($server->default_deploy_path ?: '/'),
            'cpuHistory' => $history->pluck('cpu_usage')->all(),
            'memHistory' => $history->map->memoryUsedPercent()->all(),
            'historyFrom' => $history->first()?->captured_at,
            'processes' => $processes,
            'processTimes' => $withProcesses()->where('captured_at', '>=', now()->subDay())->orderByDesc('captured_at')->pluck('captured_at', 'id'),
            'hostKey' => SshHostKey::query()->where('alias', $server->ssh_alias)->whereNull('revoked_at')->latest('trusted_at')->first(),
            'targets' => $server->targets()->active()->with(['project', 'latestDeployment'])->orderBy('path')->get(),
            'checks' => TargetHealthCheck::query()
                ->whereIn('target_id', $server->targets()->active()->select('id'))
                ->with('target.project')
                ->orderByDesc('checked_at')->orderByDesc('id')
                ->limit(self::CHECKS)
                ->get(),
            'checkDays' => Settings::int('health_retention_days'),
            'trail' => AuditEntry::query()->where('server_id', $server->id)->byHand()->orderByDesc('occurred_at')->orderByDesc('id')->limit(8)->get(),
            'adoptable' => $this->adoptable(),
            'folderCount' => $server->folderMappings()->count(),
            'intervals' => Create::INTERVALS,
            'envTags' => EnvTag::cases(),
        ])->title($server->name);
    }
}
