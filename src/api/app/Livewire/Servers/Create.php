<?php

namespace App\Livewire\Servers;

use App\Actions\Servers\RecordProbe;
use App\Enums\EnvTag;
use App\Enums\ServerStatus;
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
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Add server: pick a host from ~/.ssh/config → connection checks (trusting
 * the host key on first use) → default deploy path → review and save.
 */
#[Title('Add server')]
class Create extends Component
{
    public const INTERVALS = [60 => 'Every minute', 300 => 'Every 5 minutes', 900 => 'Every 15 minutes', 3600 => 'Every hour'];

    #[Locked]
    public int $step = 1;

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
                'source' => str_starts_with($alias->sourceFile, $sandbox) ? 'sandbox' : '~/.ssh/'.basename($alias->sourceFile).':'.$alias->line,
            ])
            ->all();
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
            $this->target = $resolver->resolve($alias)->toArray();
        } catch (SshException $e) {
            $this->target = null;
            $this->problem = $e->userMessage();
        }
    }

    // ── Step 2: connection checks ───────────────────────────────────────

    public function toConnection(): void
    {
        if ($this->alias === null || $this->target === null) {
            $this->problem = 'Pick a host first.';

            return;
        }

        $this->step = 2;
        $this->runChecks();
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
        $this->name = $this->name ?: Str::of($this->alias)->replace(['-', '_', '.'], ' ')->ucfirst()->toString();
        $this->step = 4;
    }

    public function back(): void
    {
        $this->problem = null;
        $this->step = max(1, $this->step - 1);
    }

    public function save(RecordProbe $record): void
    {
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
