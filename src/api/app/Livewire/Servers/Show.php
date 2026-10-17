<?php

namespace App\Livewire\Servers;

use App\Actions\Deployments\StartDeployment;
use App\Actions\Projects\AssignProject;
use App\Actions\Servers\CheckRoute;
use App\Actions\Servers\RecordProbe;
use App\Deploy\DeploymentBusy;
use App\Enums\AuditAction;
use App\Enums\DeploymentKind;
use App\Enums\EnvTag;
use App\Enums\RouteKind;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Models\SshHostKey;
use App\Models\Target;
use App\Models\TargetHealthCheck;
use App\Platform\Remote\RemoteFs;
use App\Platform\Ssh\Exceptions\SshException;
use App\Platform\Ssh\Route\Route;
use App\Platform\Ssh\SshConfigReader;
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
    use ConfirmsIdentity;

    // How many recent health checks the Checks tab lists.
    public const CHECKS = 50;

    // And how many route checks.
    public const ROUTE_CHECKS = 30;

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

    // The route: how deploy.me reaches the server. The password is never
    // sent to the page; left empty, the saved one stays.
    public string $routeKind = 'direct';

    public string $routeHost = '';

    public string $routePort = '';

    public string $routeUser = '';

    public string $routePassword = '';

    public string $routeJump = '';

    public string $routeCheckHost = '';

    public string $routeCheckPort = '';

    public ?string $routeNotice = null;

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(Server $server): void
    {
        abort_if($server->archived_at !== null, 404);

        $this->server = $server;
        $this->fillSettings();
        $this->fillRoute();
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

    /**
     * Checks the way to the server now: the VPN or proxy or jump host, then
     * the server through it. Kept like the checks before probes and deploys.
     */
    public function checkRoute(CheckRoute $check): void
    {
        $this->reset('routeNotice');
        $result = $check($this->server, 'manual');
        $this->server->refresh();
        $this->routeNotice = $result === null ? 'Direct: there’s no route to check.' : null;
    }

    public function pickRouteKind(string $kind): void
    {
        $kind = RouteKind::tryFrom($kind) ?? RouteKind::Direct;
        $this->routeKind = $kind->value;
        $this->resetValidation();

        if ($kind->isProxy() && $this->routePort === '') {
            $this->routePort = (string) $kind->defaultPort();
        }
    }

    public function saveRoute(CheckRoute $check): void
    {
        $this->reset('routeNotice');
        $this->resetValidation();
        $kind = RouteKind::tryFrom($this->routeKind) ?? RouteKind::Direct;
        $user = trim($this->routeUser) ?: null;
        // A new password replaces the saved one; no user means no password.
        $password = $user === null ? null : ($this->routePassword !== '' ? $this->routePassword : $this->server->route_secret);
        $number = fn (string $value) => ctype_digit(trim($value)) ? (int) trim($value) : null;

        $route = new Route(
            kind: $kind,
            host: trim($this->routeHost) ?: null,
            port: $number($this->routePort),
            user: $user,
            password: $kind->isProxy() ? $password : null,
            jump: trim($this->routeJump) ?: null,
            checkHost: trim($this->routeCheckHost) ?: null,
            checkPort: $number($this->routeCheckPort),
        );

        if (($problem = $route->problem($this->server->ssh_alias)) !== null) {
            $this->addError($problem[0], $problem[1]);

            return;
        }

        // Where ssh goes and with which proxy password: a change asks for a passkey.
        $changed = $kind !== ($this->server->route_kind ?? RouteKind::Direct)
            || $route->settings() !== $this->server->route
            || $route->password !== ($this->server->route_secret ?: null);

        if ($changed && ! $this->confirmIdentity(route('servers.show', ['server' => $this->server, 'tab' => 'settings']))) {
            return;
        }

        $this->server->forceFill([
            'route_kind' => $kind,
            'route' => $route->settings(),
            'route_secret' => $route->password,
            'route_state' => null,
            'route_detail' => null,
            'route_checked_at' => null,
        ])->save();

        Audit::record(AuditAction::RouteChanged, [
            'subject' => $this->server->name.' ('.$this->server->ssh_alias.')',
            'server_id' => $this->server->id,
            'detail' => 'now '.$kind->label(),
        ]);

        // Straight away, so the page says whether the new route works.
        $check($this->server, 'manual');
        $this->server->refresh();
        $this->fillRoute();
        $this->routeNotice = 'Route saved.';
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

    private function fillRoute(): void
    {
        $route = $this->server->connectionRoute();
        $this->routeKind = $route->kind->value;
        $this->routeHost = (string) $route->host;
        $this->routePort = $route->port !== null ? (string) $route->port : '';
        $this->routeUser = (string) $route->user;
        $this->routePassword = '';
        $this->routeJump = (string) $route->jump;
        $this->routeCheckHost = (string) $route->checkHost;
        $this->routeCheckPort = $route->checkPort !== null ? (string) $route->checkPort : '';
    }

    public function render(): View
    {
        $server = $this->server;
        $latest = $server->snapshots()->latest('captured_at')->first();
        $history = ServerSnapshot::history([$server->id], now()->subDay())->get($server->id, collect());

        // The busiest processes: the latest probe's lists, or the earlier probe's picked.
        $withProcesses = fn () => $server->snapshots()->whereNotNull('processes');
        $processes = ($this->processesAt !== '' ? $withProcesses()->find((int) $this->processesAt) : null)
            ?? $withProcesses()->latest('captured_at')->first();

        return view('livewire.servers.show', [
            'latest' => $latest,
            'disk' => $latest?->diskFor($server->default_deploy_path ?: '/'),
            'cpuHistory' => $history->pluck('cpu')->all(),
            'memHistory' => $history->pluck('mem')->all(),
            'historyFrom' => $history->first()?->at,
            'processes' => $processes,
            'processTimes' => ServerSnapshot::processTimes($server->id, now()->subDay()),
            'hostKey' => SshHostKey::query()->where('alias', $server->ssh_alias)->whereNull('revoked_at')->latest('trusted_at')->first(),
            'targets' => $server->targets()->active()->with(['project', 'latestDeployment'])->orderBy('path')->get(),
            'checks' => TargetHealthCheck::query()
                ->whereIn('target_id', $server->targets()->active()->select('id'))
                ->with('target.project')
                ->orderByDesc('checked_at')->orderByDesc('id')
                ->limit(self::CHECKS)
                ->get(),
            'checkDays' => Settings::int('health_retention_days'),
            'routeChecks' => $server->hasRoute() || $server->routeChecks()->exists()
                ? $server->routeChecks()->with('deployment')->limit(self::ROUTE_CHECKS)->get()
                : collect(),
            'route' => $server->connectionRoute(),
            'routeKinds' => RouteKind::cases(),
            'jumpChoices' => $this->tab === 'settings'
                ? rescue(fn () => collect(app(SshConfigReader::class)->aliases())->map->name->reject(fn (string $name) => $name === $server->ssh_alias)->values()->all(), [], report: false)
                : [],
            'trail' => AuditEntry::query()->where('server_id', $server->id)->byHand()->orderByDesc('occurred_at')->orderByDesc('id')->limit(8)->get(),
            'adoptable' => $this->adoptable(),
            'folderCount' => $server->folderMappings()->count(),
            'intervals' => Create::INTERVALS,
            'envTags' => EnvTag::cases(),
        ])->title($server->name);
    }
}
