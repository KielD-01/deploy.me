<?php

namespace App\Livewire\Servers;

use App\Actions\Servers\RecordProbe;
use App\Actions\Servers\SaveManualHost;
use App\Enums\AuditAction;
use App\Enums\EnvTag;
use App\Enums\ServerStatus;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Livewire\Concerns\EditsManualHost;
use App\Models\ManualHost;
use App\Models\Server;
use App\Models\SshHostKey;
use App\Platform\Remote\ProbeResult;
use App\Platform\Remote\RemoteFs;
use App\Platform\Remote\ServerProber;
use App\Platform\Ssh\Exceptions\AuthenticationFailed;
use App\Platform\Ssh\Exceptions\HostKeyChanged;
use App\Platform\Ssh\Exceptions\HostKeyUnknown;
use App\Platform\Ssh\Exceptions\SshException;
use App\Platform\Ssh\HostKeyScan;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\SshConfigReader;
use App\Platform\Ssh\SshTargetResolver;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Add server: pick a host from ~/.ssh/config, or enter one by hand (host,
 * user, port, the key's path) → connection checks (trusting the host key on
 * first use) → default deploy path → review and save.
 */
#[Title('Add server')]
class Create extends Component
{
    use ConfirmsIdentity;
    use EditsManualHost {
        updatedManualName as aliasFollowsName;
    }

    public const INTERVALS = [60 => 'Every minute', 300 => 'Every 5 minutes', 900 => 'Every 15 minutes', 3600 => 'Every hour'];

    #[Locked]
    public int $step = 1;

    // config: pick a host from ~/.ssh/config · manual: type one in.
    #[Locked]
    public string $mode = 'config';

    // The server entered by hand on this page: Continue saves it, and saves
    // it again (not a second one) after a Back.
    #[Locked]
    public ?int $manualHostId = null;

    public string $search = '';

    public bool $showGitHosts = false;

    #[Locked]
    public ?string $alias = null;

    /** @var array<string, mixed>|null resolved by `ssh -G` */
    #[Locked]
    public ?array $target = null;

    /** @var list<array{key: string, label: string, state: string, detail: string}> */
    #[Locked]
    public array $checks = [];

    /** @var array<string, mixed>|null a host key waiting for your decision */
    #[Locked]
    public ?array $hostKey = null;

    #[Locked]
    public ?string $problem = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $probe = null;

    #[Locked]
    public ?int $latency = null;

    /** @var list<string> */
    #[Locked]
    public array $log = [];

    public string $path = '';

    public string $name = '';

    public string $envTag = 'development';

    public int $probeInterval = 300;

    // ── Step 1: pick a host ─────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function hosts(): array
    {
        $added = Server::query()->active()->pluck('ssh_alias')->all();
        $sandbox = storage_path('app/sandbox-ssh');

        return collect(app(SshConfigReader::class)->aliases())
            ->map(fn ($alias) => [
                'name' => $alias->name,
                'hostName' => $alias->hostName,
                'git' => $alias->looksLikeGitHost(),
                'added' => in_array($alias->name, $added, true),
                'sandbox' => str_starts_with($alias->sourceFile, $sandbox),
                'manual' => false,
                'source' => str_starts_with($alias->sourceFile, $sandbox) ? 'sandbox' : '~/.ssh/'.basename($alias->sourceFile).':'.$alias->line,
            ])
            ->merge(ManualHost::query()->orderBy('alias')->get()->map(fn (ManualHost $host) => [
                'name' => $host->alias,
                'hostName' => $host->host_name,
                'git' => false,
                'added' => in_array($host->alias, $added, true),
                'sandbox' => false,
                'manual' => true,
                'source' => 'entered in deploy.me',
            ]))
            ->all();
    }

    #[Computed]
    public function manualCount(): int
    {
        return ManualHost::query()->count();
    }

    /**
     * Hosts a server entered here can jump through.
     *
     * @return list<string>
     */
    #[Computed]
    public function jumpChoices(): array
    {
        return app(SaveManualHost::class)->jumpChoices(trim($this->manualAlias) ?: null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function visibleHosts(): array
    {
        $needle = Str::lower(trim($this->search));

        return collect($this->hosts)
            ->filter(fn ($host) => $this->showGitHosts || ! $host['git'])
            ->filter(fn ($host) => $needle === '' || str_contains(Str::lower($host['name'].' '.$host['hostName']), $needle))
            ->sortBy(fn ($host) => [$host['added'], $host['name']])
            ->values()
            ->all();
    }

    #[Computed]
    public function gitHostCount(): int
    {
        return collect($this->hosts)->where('git', true)->count();
    }

    public function select(string $alias, SshTargetResolver $resolver): void
    {
        $host = collect($this->hosts)->firstWhere('name', $alias);

        if ($host === null || $host['added']) {
            return;
        }

        $this->alias = $alias;
        $this->problem = null;

        try {
            $this->target = $this->targetForDisplay($resolver->resolve($alias)->toArray());
        } catch (SshException $e) {
            $this->target = null;
            $this->problem = $e->userMessage();
        }
    }

    // ── Step 1, by hand: host, user, port and the key ──────────────────

    // The name typed here is the one Review starts from, however often it changes.
    public function updatedManualName(): void
    {
        $this->aliasFollowsName();
        $this->name = '';
    }

    public function enterManually(): void
    {
        $this->mode = 'manual';
        $this->problem = null;
    }

    public function pickFromList(): void
    {
        $this->mode = 'config';
        $this->problem = null;
        $this->resetValidation();
    }

    /**
     * Opens a server entered here earlier (and not added yet) in the form.
     */
    public function editManual(string $alias): void
    {
        $host = ManualHost::query()->where('alias', $alias)->first();

        if ($host === null || $host->server()->exists()) {
            return;
        }

        $this->fillManual($host);
        $this->manualName = '';
        $this->manualHostId = $host->id;
        $this->alias = null;
        $this->target = null;
        $this->enterManually();
    }

    /**
     * Forgets a server entered here that no server uses (one left behind
     * when an earlier Add server stopped halfway).
     */
    public function deleteManual(string $alias): void
    {
        if (! $this->confirmIdentity(route('servers.create'))) {
            return;
        }

        $host = ManualHost::query()->where('alias', $alias)->first();

        if ($host === null || $host->server()->exists()) {
            return;
        }

        $host->delete();
        Audit::record(AuditAction::ManualHostRemoved, ['subject' => $alias, 'detail' => 'no server used it']);

        if ($this->alias === $alias) {
            $this->reset('alias', 'target');
        }
        if ($this->manualHostId === $host->id) {
            $this->manualHostId = null;
        }

        unset($this->hosts, $this->visibleHosts, $this->manualCount);
    }

    // ── Step 2: connection checks ───────────────────────────────────────

    public function toConnection(SshTargetResolver $resolver): void
    {
        if ($this->mode === 'manual' && ! $this->saveManual($resolver)) {
            return;
        }

        if ($this->alias === null || $this->target === null) {
            $this->problem = 'Pick a host first.';

            return;
        }

        $this->step = 2;
        $this->runChecks();
    }

    /**
     * Saves the server typed in (its Host block goes into deploy.me's ssh
     * config) and resolves it like any alias.
     */
    private function saveManual(SshTargetResolver $resolver): bool
    {
        // The route's password.confirm only sees the page load, not its updates.
        if (! $this->confirmIdentity(route('servers.create'))) {
            return false;
        }

        $this->problem = null;
        $saved = $this->manualHostId !== null ? ManualHost::query()->find($this->manualHostId) : null;
        $host = $this->saveManualHost($saved);

        if ($host === null) {
            return false;
        }

        // Back and Continue again: check what was saved now, not the connection the first check left open.
        if ($saved !== null) {
            $this->closeOpenConnection($host->alias);
        }

        $this->manualHostId = $host->id;
        $this->alias = $host->alias;
        unset($this->hosts, $this->visibleHosts, $this->manualCount);

        try {
            $this->target = $this->targetForDisplay($resolver->resolve($host->alias)->toArray());
        } catch (SshException $e) {
            $this->target = null;
            $this->problem = $e->userMessage();

            return false;
        }

        return true;
    }

    public function runChecks(): void
    {
        $this->reset('checks', 'hostKey', 'problem', 'probe', 'latency', 'log');
        $target = $this->target;
        $endpoint = "{$target['hostName']}:{$target['port']}";
        $prober = app(ServerProber::class);

        $this->log[] = "$ ssh {$this->alias} true";

        try {
            $this->latency = $prober->ping($this->alias);
        } catch (HostKeyUnknown) {
            $this->check('connect', 'Resolve and connect', 'ok', $endpoint);
            $this->scanHostKey();

            return;
        } catch (HostKeyChanged $e) {
            $this->check('connect', 'Resolve and connect', 'ok', $endpoint);
            $this->check('hostkey', 'Host key', 'fail', 'Changed since it was trusted');
            $this->fail($e);

            return;
        } catch (AuthenticationFailed $e) {
            $this->check('connect', 'Resolve and connect', 'ok', $endpoint);
            $this->check('hostkey', 'Host key', 'ok', 'Trusted');
            $this->check('auth', 'Authenticate', 'fail', 'Key refused by the server');
            $this->fail($e);

            return;
        } catch (SshException $e) {
            $this->check('connect', 'Resolve and connect', 'fail', $endpoint);
            $this->fail($e);

            return;
        }

        $trustedHere = SshHostKey::query()->where('alias', $this->alias)->whereNull('revoked_at')->exists();
        $this->check('connect', 'Resolve and connect', 'ok', "{$endpoint} · {$this->latency} ms");
        $this->check('hostkey', 'Host key', 'ok', $trustedHere ? 'Trusted in deploy.me' : 'Known from your ~/.ssh/known_hosts');
        $this->check('auth', 'Authenticate', 'ok', $target['user'].' with '.basename($target['identityFiles'][0] ?? 'the default key'));
        $this->log[] = "Connected in {$this->latency} ms";

        $this->log[] = "$ ssh {$this->alias} sh -s < probe.sh";

        try {
            $probe = $prober->probe($this->alias);
        } catch (SshException $e) {
            $this->check('system', 'Read system info', 'fail', 'The probe failed');
            $this->fail($e);

            return;
        }

        $this->probe = $probe->data;
        $this->log[] = 'Probe finished: '.$probe->os();

        foreach ($this->checksFromProbe($probe) as $check) {
            $this->check(...$check);
        }
    }

    private function scanHostKey(): void
    {
        $this->log[] = 'Host key isn’t in known_hosts; asking the server for it';

        try {
            $scan = app(KnownHosts::class)->scan($this->alias);
        } catch (SshException $e) {
            $this->check('hostkey', 'Host key', 'fail', 'The server offered no key');
            $this->fail($e);

            return;
        }

        $this->hostKey = $scan->toArray();
        $this->check('hostkey', 'Unknown host key', 'pending', 'First connection to this server');
        $this->log[] = collect($scan->keys)->map(fn ($key) => "{$key['type']} {$key['fingerprint']}")->implode(', ');
        $this->log[] = 'Waiting for you to trust the key…';
    }

    /**
     * Trusts the key you compared, after checking the server still offers exactly that key.
     */
    public function trustHostKey(KnownHosts $knownHosts): void
    {
        // The route's password.confirm only sees the page load, not its updates.
        if (! $this->confirmIdentity(route('servers.create'))) {
            return;
        }

        if ($this->hostKey === null) {
            return;
        }

        $shown = HostKeyScan::fromArray($this->hostKey);

        try {
            $now = $knownHosts->scan($this->alias);
        } catch (SshException $e) {
            $this->fail($e);

            return;
        }

        if ($now->fingerprints() !== $shown->fingerprints()) {
            $this->hostKey = $now->toArray();
            $this->problem = 'The server offered a different key than the one shown. Compare the new fingerprint before trusting it.';

            return;
        }

        $knownHosts->trust($now, auth()->id());
        $this->runChecks();
    }

    // ── Step 3 and 4 ────────────────────────────────────────────────────

    public function toPath(RemoteFs $fs): void
    {
        if ($this->probe === null) {
            return;
        }

        // Start in /opt when the server has one, else the login user's home. Set it
        // here: a value the folder browser picks while mounting doesn't flow back up.
        if ($this->path === '') {
            try {
                $this->path = $fs->isDirectory($this->alias, '/opt') ? '/opt' : $fs->home($this->alias);
            } catch (SshException $e) {
                $this->fail($e);

                return;
            }
        }

        $this->step = 3;
    }

    public function toReview(): void
    {
        if (! str_starts_with($this->path, '/')) {
            $this->problem = 'Pick a folder first.';

            return;
        }

        if ((new Server(['reserved_paths' => []]))->isReserved($this->path)) {
            $this->problem = "{$this->path} is reserved for the shared infrastructure. Pick another folder.";

            return;
        }

        $this->problem = null;
        $typed = $this->mode === 'manual' ? trim($this->manualName) : '';
        $this->name = $this->name ?: ($typed !== '' ? $typed : Str::of($this->alias)->replace(['-', '_', '.'], ' ')->ucfirst()->toString());
        $this->step = 4;
    }

    public function back(): void
    {
        $this->problem = null;
        $this->step = max(1, $this->step - 1);
    }

    public function save(RecordProbe $record): void
    {
        if (! $this->confirmIdentity(route('servers.create'))) {
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'envTag' => ['required', Rule::enum(EnvTag::class)],
            'probeInterval' => ['required', Rule::in(array_keys(self::INTERVALS))],
            'path' => ['required', 'string', 'starts_with:/', 'max:255'],
        ]);

        if ($this->probe === null || $this->target === null || Server::query()->active()->where('ssh_alias', $this->alias)->exists()) {
            $this->problem = 'This host is already added, or its checks haven’t run.';

            return;
        }

        $server = Server::query()->create([
            'name' => $this->name,
            'slug' => Server::uniqueSlug($this->name),
            'ssh_alias' => $this->alias,
            'host_name' => $this->target['hostName'],
            'ssh_user' => $this->target['user'],
            'port' => $this->target['port'],
            'identity_files' => $this->target['identityFiles'],
            'proxy_jump' => $this->target['proxyJump'],
            'env_tag' => $this->envTag,
            'status' => ServerStatus::Online,
            'default_deploy_path' => rtrim($this->path, '/') ?: '/',
            'reserved_paths' => config('deployme.reserved_paths'),
            'probe_interval' => $this->probeInterval,
        ]);

        $record->store($server, new ProbeResult($this->probe), $this->latency);

        $this->redirectRoute('servers.show', $server, navigate: true);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function checksFromProbe(ProbeResult $probe): array
    {
        $c = $probe->capabilities();

        return [
            ['system', 'System', 'ok', $probe->os().' · '.($probe->data['arch'] ?? '')],
            ['docker', 'Docker and Compose', $c['docker'] && $c['compose'] ? 'ok' : 'warn',
                $c['docker'] ? "Docker {$c['docker']} · Compose ".($c['compose'] ? "v{$c['compose']}" : 'missing') : ($c['docker_error'] ?: 'Docker isn’t installed')],
            ['tools', 'git and rsync', $c['git'] && $c['rsync'] ? 'ok' : 'warn', 'git '.($c['git'] ?: 'missing').' · rsync '.($c['rsync'] ?: 'missing')],
            ['coreutils', 'GNU mv and flock', $c['gnu_mv'] && $c['flock'] ? 'ok' : 'warn', $c['gnu_mv'] && $c['flock'] ? 'Release switches can be atomic' : 'Needed for atomic release switches'],
            ['edge', 'Edge proxy', $c['caddy_docker_proxy'] && $c['caddy_on_network'] ? 'ok' : 'info',
                $c['caddy_docker_proxy'] ? "{$c['caddy_docker_proxy']} on proxy-network" : 'No caddy-docker-proxy (only matters for Docker projects)'],
            ['access', 'sudo and docker group', $c['docker_group'] ? 'ok' : 'warn',
                ($c['docker_group'] ? 'In the docker group' : 'Not in the docker group').' · '.($c['sudo_nopasswd'] ? 'passwordless sudo' : 'sudo needs a password')],
        ];
    }

    private function check(string $key, string $label, string $state, string $detail): void
    {
        $this->checks = array_values(array_filter($this->checks, fn ($check) => $check['key'] !== $key));
        $this->checks[] = compact('key', 'label', 'state', 'detail');
    }

    private function fail(SshException $e): void
    {
        $this->problem = $e->userMessage();
        $this->log[] = trim($e->stderr) ?: $e->getMessage();
    }

    public function render(): View
    {
        $disk = $this->probe ? (new ProbeResult($this->probe))->diskFor($this->path ?: '/') : null;

        return view('livewire.servers.create', ['disk' => $disk, 'intervals' => self::INTERVALS, 'envTags' => EnvTag::cases()]);
    }
}
