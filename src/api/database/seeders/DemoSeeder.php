<?php

namespace Database\Seeders;

use App\Actions\Folders\RunFolderSync;
use App\Actions\Folders\StartFolderSync;
use App\Actions\Projects\AssignProject;
use App\Actions\Projects\ImportProject;
use App\Actions\Servers\RecordProbe;
use App\Actions\Targets\CheckTargetHealth;
use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\AuditAction;
use App\Enums\CiState;
use App\Enums\DeploymentKind;
use App\Enums\EnvFileRole;
use App\Enums\EnvTag;
use App\Enums\ProjectSource;
use App\Enums\ServerStatus;
use App\Enums\SyncDirection;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\FolderMapping;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Server;
use App\Models\Target;
use App\Models\User;
use App\Platform\Detection\Detection;
use App\Platform\Detection\LocalInspector;
use App\Platform\Ssh\AuditingSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use App\Support\Audit;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Example data for screenshots and demos (docs/gallery.md): three servers,
 * four projects with git histories of their own, a week of runs, probes,
 * health checks, a folder sync and the audit log. All of it goes through
 * deploy.me's own code: a fake ssh client answers the way a server does, and
 * the clock moves along with it. Nothing leaves the machine.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Only on an empty database: it refuses where servers or projects exist. The
 * projects live in the host home's Projects folder (DEPLOYME_HOST_HOME, or
 * DEMO_HOME to seed somewhere else), which has to be in DEPLOYME_PROJECT_ROOTS.
 */
class DemoSeeder extends Seeder
{
    private FakeSshClient $ssh;

    private string $home;

    /** @var array<string, string> repository => the commit CI has images for */
    private array $ciHead = [];

    /** @var array<string, int> how often a script answered within the current run */
    private array $calls = [];

    // The real moment the seeding started: the history ends here.
    private Carbon $now;

    private bool $rsyncFails = false;

    public function run(): void
    {
        if (Server::query()->exists() || Project::query()->exists()) {
            throw new RuntimeException('DemoSeeder only fills an empty database.');
        }

        // The demo home is the host home (DEPLOYME_HOST_HOME, or DEMO_HOME for
        // a seed on the side): the projects go in its Projects folder, which
        // has to be one of the project roots.
        $this->home = rtrim((string) (env('DEMO_HOME') ?: config('deployme.host_home') ?: '/Users/demo'), '/');
        if (! in_array("{$this->home}/Projects", config('deployme.project_roots'), true)) {
            throw new RuntimeException("Put {$this->home}/Projects in DEPLOYME_PROJECT_ROOTS first.");
        }

        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => fn (Request $request) => $this->github($request), 'ghcr.io/*' => fn (Request $request) => str_contains($request->url(), '/token') ? Http::response(['token' => 'registry-token']) : Http::response('', 200), 'api.telegram.org/*' => Http::response(['ok' => true])]);
        Queue::fake();
        Process::fake(['*rsync*' => fn ($process) => $this->rsync($process->command)]);
        config(['deployme.sync.poll_ms' => 0, 'deployme.deploy.cancel_check_ms' => 60000]);

        $this->ssh = new FakeSshClient;
        app()->instance(SshClient::class, new AuditingSshClient($this->ssh));

        $user = User::query()->first() ?? User::query()->create(['name' => 'Demo', 'email' => 'demo@deployme.localhost', 'password' => bin2hex(random_bytes(16))]);
        $user->forceFill(['name' => 'Roman'])->save();

        $this->now = now()->copy();
        $start = $this->now->copy()->subDays(6)->setTime(9, 40);
        Carbon::setTestNow($start);
        Auth::setUser($user);

        Credential::query()->create([
            'provider' => 'github', 'login' => 'KielD-01', 'owners' => ['KielD-01'], 'token' => 'ghp_'.bin2hex(random_bytes(18)),
            'token_hint' => 'ghp_••••demo', 'token_type' => 'fine-grained', 'scopes' => ['contents:read', 'actions:read', 'packages:read'], 'verified_at' => now(),
        ]);
        Audit::record(AuditAction::AccountCreated, ['subject' => $user->email]);
        Carbon::setTestNow(now()->addMinutes(2));
        Audit::record(AuditAction::PasswordSet, ['subject' => $user->email]);
        Audit::record(AuditAction::TwoFactorEnabled, ['subject' => $user->email]);
        Carbon::setTestNow(now()->addMinutes(3));
        Audit::record(AuditAction::CredentialSaved, ['subject' => 'GitHub token for KielD-01']);

        $servers = $this->servers();
        $projects = $this->projects();
        $targets = $this->targets($servers, $projects);

        $this->probeHistory($servers, $start);
        $this->timeline($targets, $user);
        $this->healthHistory($targets);
        $this->commitAfter($projects['atelier'], 'Gift cards at checkout', 'src/Checkout/GiftCards.php', 12);
        $this->folderSync($servers['build-box'], $user);

        Carbon::setTestNow();
        Auth::forgetUser();
        foreach ($servers as $server) {
            app(RecordProbe::class)($server);
        }
    }

    // ── Servers ─────────────────────────────────────────────────────────

    /**
     * @return array<string, Server>
     */
    private function servers(): array
    {
        $rows = [
            'edge-fra' => ['Edge · Frankfurt', '203.0.113.10', EnvTag::Production, 300],
            'stage-ams' => ['Staging · Amsterdam', '198.51.100.24', EnvTag::Staging, 300],
            'build-box' => ['Build box', '192.0.2.41', EnvTag::Development, 900],
        ];

        $servers = [];
        foreach ($rows as $alias => [$name, $ip, $env, $interval]) {
            $servers[$alias] = Server::query()->create([
                'name' => $name, 'slug' => $alias, 'ssh_alias' => $alias, 'host_name' => $ip, 'ssh_user' => 'deploy', 'port' => 22,
                'identity_files' => ['~/.ssh/id_ed25519'], 'env_tag' => $env, 'status' => ServerStatus::Online,
                'default_deploy_path' => '/opt/srv', 'reserved_paths' => [], 'probe_interval' => $interval, 'next_probe_at' => now(),
            ]);
            Audit::record(AuditAction::HostKeyTrusted, ['subject' => "{$alias} ({$ip})", 'server_id' => $servers[$alias]->id]);
        }

        return $servers;
    }

    /**
     * A probe every 20 minutes over the last day, with a busy hour or two.
     *
     * @param  array<string, Server>  $servers
     */
    private function probeHistory(array $servers, Carbon $start): void
    {
        // Scheduled work: nobody's.
        $user = Auth::user();
        Auth::forgetUser();

        $this->ssh->on('*', 'ping', fn (string $alias) => new SshResult(0, '', '', ['edge-fra' => 0.021, 'stage-ams' => 0.034][$alias] ?? 0.008 + mt_rand(0, 6) / 1000));
        $this->ssh->on('*', 'probe', fn (string $alias) => FakeSshClient::ok("__DEPLOYME_PROBE_BEGIN__\n".json_encode($this->probe($alias))."\n__DEPLOYME_PROBE_END__\n"));

        for ($at = $this->now->copy()->subDay(); $at->lt($this->now); $at = $at->copy()->addMinutes(20)) {
            Carbon::setTestNow($at);
            foreach ($servers as $server) {
                app(RecordProbe::class)($server);
            }
        }

        Carbon::setTestNow($start);
        Auth::setUser($user);
    }

    /**
     * What probe.sh would print on each server at this moment.
     *
     * @return array<string, mixed>
     */
    private function probe(string $alias): array
    {
        $hour = (int) now()->format('G');
        $wave = (sin(now()->getTimestamp() / 5400) + 1) / 2;
        $busy = $hour >= 10 && $hour <= 18 ? 1.0 : 0.35;
        [$cores, $memGb, $base, $diskUsed, $memBase] = match ($alias) {
            'edge-fra' => [8, 32, 18, 0.41, 0.62],
            'stage-ams' => [4, 8, 9, 0.58, 0.38],
            default => [16, 64, 12, 0.73, 0.81],
        };
        $usage = round(min(97, $base * $busy + 22 * $wave * $busy + mt_rand(0, 40) / 10), 1);
        $gb = 1024 ** 3;
        $process = fn (int $pid, string $name, ?string $container, array $reading) => ['pid' => $pid, 'name' => $name, 'container' => $container, ...$reading];

        $top = match ($alias) {
            'edge-fra' => [
                ['frankenphp', 'warehouse-api-blue-1'], ['frankenphp', 'warehouse-api-blue-2'], ['php', 'warehouse-queue-1'], ['postgres', 'postgres'],
                ['caddy', 'caddy'], ['php-fpm', 'atelier-app-1'], ['valkey-server', 'valkey'], ['node', 'warehouse-web-blue-1'], ['dockerd', null], ['containerd', null],
            ],
            'stage-ams' => [
                ['frankenphp', 'warehouse-api-green-1'], ['postgres', 'postgres'], ['php', 'warehouse-queue-1'], ['caddy', 'caddy'],
                ['node', 'warehouse-web-green-1'], ['valkey-server', 'valkey'], ['dockerd', null], ['containerd', null], ['sshd', null], ['systemd-journal', null],
            ],
            default => [
                ['frankenphp', 'reviews-app-1'], ['php', 'reviews-scheduler-1'], ['meilisearch', 'reviews-search-1'], ['postgres', 'postgres'],
                ['buildkitd', 'buildx_buildkit_default'], ['caddy', 'caddy'], ['dockerd', null], ['containerd', null], ['rsync', null], ['sshd', null],
            ],
        };

        $cpu = $mem = $net = [];
        foreach ($top as $i => [$name, $container]) {
            $pid = 1200 + $i * 317;
            $cpu[] = $process($pid, $name, $container, ['cpu' => round(max(0.1, ($usage * $cores / 3) / ($i + 1.4) + mt_rand(0, 30) / 10), 1)]);
            $mem[] = $process($pid, $name, $container, ['rss' => (int) (($memGb * $gb * 0.11) / ($i + 1) + mt_rand(5, 60) * 1024 ** 2)]);
            if ($i < 7) {
                $net[] = $process($pid, $name, $container, ['tx' => round((180_000 * $busy * ($wave + 0.3)) / ($i + 1) + mt_rand(100, 9000), 1), 'rx' => round((42_000 * $busy * ($wave + 0.3)) / ($i + 1) + mt_rand(100, 4000), 1)]);
            }
        }
        usort($cpu, fn ($a, $b) => $b['cpu'] <=> $a['cpu']);
        usort($mem, fn ($a, $b) => $b['rss'] <=> $a['rss']);
        usort($net, fn ($a, $b) => ($b['tx'] + $b['rx']) <=> ($a['tx'] + $a['rx']));

        $stacks = match ($alias) {
            'edge-fra' => [['Name' => 'infra', 'Status' => 'running(4)', 'ConfigFiles' => '/opt/infra/compose.yaml'], ['Name' => 'warehouse', 'Status' => 'running(5)', 'ConfigFiles' => '/opt/srv/warehouse/current/compose.yaml'], ['Name' => 'atelier', 'Status' => 'running(2)', 'ConfigFiles' => '/opt/srv/atelier/current/compose.yaml']],
            'stage-ams' => [['Name' => 'infra', 'Status' => 'running(3)', 'ConfigFiles' => '/opt/infra/compose.yaml'], ['Name' => 'warehouse', 'Status' => 'running(4)', 'ConfigFiles' => '/opt/srv/warehouse/current/compose.yaml']],
            default => [['Name' => 'infra', 'Status' => 'running(3)', 'ConfigFiles' => '/opt/infra/compose.yaml'], ['Name' => 'reviews', 'Status' => 'running(4)', 'ConfigFiles' => '/opt/srv/reviews/compose.yaml'], ['Name' => 'buildx', 'Status' => 'running(1)', 'ConfigFiles' => '']],
        };

        return [
            'v' => 1, 'hostname' => $alias, 'user' => 'deploy', 'home' => '/home/deploy', 'arch' => $alias === 'build-box' ? 'aarch64' : 'x86_64',
            'kernel' => '6.8.0-47-generic', 'os' => ['id' => 'ubuntu', 'version' => '24.04', 'pretty' => 'Ubuntu 24.04.3 LTS'],
            'uptime' => 2_400_000 + now()->getTimestamp() % 86400,
            'cpu' => ['model' => $alias === 'build-box' ? 'Ampere Altra' : 'AMD EPYC 9454P', 'cores' => $cores, 'threads' => $cores, 'usage' => $usage, 'mhz' => 2750, 'mhz_max' => 3800,
                'load' => [round($usage / 100 * $cores, 2), round($usage / 110 * $cores, 2), round($usage / 125 * $cores, 2)]],
            'mem' => ['total' => $memGb * $gb, 'available' => (int) ($memGb * $gb * ($memBase - 0.22 * $busy * $wave)), 'swap_total' => 2 * $gb, 'swap_free' => 2 * $gb - 180 * 1024 ** 2],
            'disks' => [['source' => '/dev/nvme0n1p1', 'fstype' => 'ext4', 'size' => 480 * $gb, 'used' => (int) (480 * $gb * $diskUsed), 'available' => (int) (480 * $gb * (1 - $diskUsed)), 'mount' => '/']],
            'tools' => ['bash' => '/usr/bin/bash', 'git' => '2.43.0', 'rsync' => '3.2.7', 'docker_client' => '28.4.0', 'docker_server' => '28.4.0', 'docker_error' => '', 'compose' => '2.39.4', 'gnu_mv' => true, 'flock' => true],
            'proxy' => ['network' => 'bridge', 'caddy_docker_proxy' => 'caddy', 'caddy_on_network' => true],
            'access' => ['uid' => 1000, 'sudo_nopasswd' => true, 'docker_group' => true],
            'stacks' => $stacks,
            'top' => ['window' => 1.02, 'cpu' => $cpu, 'mem' => $mem, 'net' => $net, 'net_note' => null],
        ];
    }

    // ── Projects ────────────────────────────────────────────────────────

    /**
     * @return array<string, Project>
     */
    private function projects(): array
    {
        File::ensureDirectoryExists("{$this->home}/Projects");

        $warehouse = $this->repository('warehouse', [
            'compose.yaml' => <<<'YAML'
                name: warehouse

                networks:
                  proxy-network:
                    external: true

                x-app: &app
                  image: ghcr.io/kield-01/warehouse-api:${API_TAG:-latest}
                  restart: unless-stopped
                  env_file: .env
                  networks: [proxy-network]

                services:
                  api:
                    <<: *app
                    labels:
                      caddy: api.warehouse.example.com
                      caddy.reverse_proxy: "{{upstreams 8000}}"
                    healthcheck:
                      test: ["CMD", "curl", "-fs", "http://localhost:8000/up"]
                      interval: 5s
                    stop_grace_period: 30s
                  web:
                    image: ghcr.io/kield-01/warehouse-web:${WEB_TAG:-latest}
                    restart: unless-stopped
                    labels:
                      caddy: warehouse.example.com
                      caddy.reverse_proxy: "{{upstreams 3000}}"
                    healthcheck:
                      test: ["CMD", "wget", "-qO-", "http://localhost:3000/health"]
                      interval: 5s
                    networks: [proxy-network]
                  queue:
                    <<: *app
                    command: ["php", "artisan", "queue:work", "--tries=3"]
                    stop_grace_period: 120s
                  migrate:
                    <<: *app
                    command: ["php", "artisan", "migrate", "--force"]
                    profiles: [tools]
                YAML,
            '.github/workflows/ci-cd.yml' => <<<'YAML'
                name: CI/CD

                on:
                  push:
                    branches: [main]

                jobs:
                  images:
                    runs-on: ubuntu-latest
                    strategy:
                      matrix:
                        service: [api, web]
                    steps:
                      - uses: actions/checkout@v4
                      - id: meta
                        run: echo "short_sha=$(echo '${{ github.sha }}' | cut -c1-7)" >> "$GITHUB_OUTPUT"
                      - uses: docker/build-push-action@v6
                        with:
                          file: docker/${{ matrix.service }}/Dockerfile
                          push: true
                          tags: ghcr.io/kield-01/warehouse-${{ matrix.service }}:sha-${{ steps.meta.outputs.short_sha }}
                YAML,
            'composer.json' => "{\n    \"require\": {\n        \"laravel/framework\": \"^13.0\"\n    }\n}\n",
            'artisan' => "#!/usr/bin/env php\n<?php\n",
            '.env.example' => "APP_NAME=Warehouse\nAPP_KEY=\nDB_HOST=postgres\nDB_DATABASE=warehouse\nDB_USERNAME=warehouse\nDB_PASSWORD=\n",
        ], [
            [6, 'Stock levels per bin location', 'app/Models/Bin.php', true],
            [6, 'Barcode scanner input on the receiving screen', 'resources/js/receiving.ts'],
            [5, 'Faster picking-list query', 'app/Queries/PickingList.php'],
            [4, 'Reserve stock when an order is placed', 'app/Actions/ReserveStock.php', true],
            [3, 'Fix rounding in pallet weights', 'app/Support/Weight.php'],
            [3, 'Export the daily stock report as CSV', 'app/Reports/DailyStock.php'],
            [2, 'Courier labels for parcels over 30 kg', 'app/Shipping/Labels.php', true],
            [1, 'Bump Laravel to 13.4', 'composer.lock'],
            [1, 'Show low-stock warnings on the dashboard', 'resources/js/dashboard.ts'],
            [0, 'Batch inventory recounts', 'app/Actions/Recount.php', true],
        ]);

        $atelier = $this->repository('atelier', [
            'public/index.php' => "<?php require __DIR__.'/../bootstrap.php';\n",
            'composer.json' => "{\n    \"require\": {\n        \"symfony/framework-bundle\": \"^7.3\"\n    }\n}\n",
            'bootstrap.php' => "<?php\n",
            'deploy.sh' => "#!/usr/bin/env bash\nset -e\nphp bin/console cache:warmup\n",
            'storage/.gitignore' => "*\n!.gitignore\n",
        ], [
            [5, 'Autumn collection pages', 'templates/autumn.twig'],
            [4, 'Lazy-load the gallery images', 'public/js/gallery.js'],
            [2, 'Booking form: pick a fitting slot', 'src/Booking/Slots.php'],
            [0, 'Translate the booking emails', 'translations/uk.yaml'],
        ]);

        $reviews = $this->repository('reviews', [
            'compose.yaml' => <<<'YAML'
                name: reviews

                services:
                  app:
                    build: .
                    image: reviews-app:${APP_TAG:-local}
                    restart: unless-stopped
                    env_file: .env
                    healthcheck:
                      test: ["CMD", "curl", "-fs", "http://localhost/up"]
                      interval: 5s
                  scheduler:
                    image: reviews-app:${APP_TAG:-local}
                    command: ["php", "artisan", "schedule:work"]
                  search:
                    image: getmeili/meilisearch:v1.15
                YAML,
            'Dockerfile' => "FROM dunglas/frankenphp:1-php8.5\nCOPY . /app\n",
            'composer.json' => "{\n    \"require\": {\n        \"laravel/framework\": \"^13.0\"\n    }\n}\n",
            'artisan' => "#!/usr/bin/env php\n<?php\n",
        ], [
            [5, 'Reply to a review from the admin', 'app/Http/Controllers/ReplyController.php'],
            [3, 'Search reviews by product', 'app/Search/Reviews.php'],
            [1, 'Flag reviews that look like spam', 'app/Moderation/Spam.php', true],
        ]);

        $import = fn (string $path, string $name, array $settings) => app(ImportProject::class)(
            new Detection(app(LocalInspector::class)->inspect($path)),
            ProjectSource::Local,
            ['name' => $name, 'slug' => basename($path), 'branch' => 'main', 'versionable' => true, 'keep_releases' => 5, 'dockerized' => false, 'stack_name' => null, 'compose_file' => null, ...$settings],
        );

        $projects = [];

        $projects['warehouse'] = $import($warehouse, 'Warehouse', [
            'dockerized' => true, 'stack_name' => 'warehouse', 'compose_file' => 'compose.yaml',
            'strategies' => [['key' => 'ghcr_image', 'enabled' => true], ['key' => 'push_archive', 'enabled' => true], ['key' => 'push_rsync', 'enabled' => false], ['key' => 'server_git', 'enabled' => false]],
        ]);
        $projects['warehouse']->update([
            'blue_green' => true, 'auto_rollback' => true, 'auto_deploy' => true, 'health_url' => 'https://api.warehouse.example.com/up', 'health_timeout' => 60,
            'shared_paths' => ['storage'], 'migrate_service' => 'migrate', 'backup_keep' => 7,
            'ci_state' => CiState::Passing, 'ci_checked_at' => now(),
        ]);
        $projects['warehouse']->envFiles()->create(['path' => '.env', 'role' => EnvFileRole::Compose, ...ProjectEnvFile::contentAttributes(
            "APP_NAME=Warehouse\nAPP_ENV=production\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nAPP_URL=https://warehouse.example.com\n\nDB_HOST=postgres\nDB_DATABASE=warehouse\nDB_USERNAME=warehouse\nDB_PASSWORD=".bin2hex(random_bytes(12))."\n\nREDIS_HOST=valkey\nREDIS_PREFIX=warehouse_\nQUEUE_CONNECTION=redis\nMAIL_MAILER=postmark\nPOSTMARK_TOKEN=".bin2hex(random_bytes(16))."\n",
        )]);
        $projects['warehouse']->hooks()->create(['phase' => 'after_activate', 'name' => 'Warm the caches', 'command' => 'php artisan optimize', 'run_in' => 'service_exec', 'service' => 'api', 'timeout' => 120, 'position' => 1]);

        $projects['atelier'] = $import($atelier, 'Atelier', [
            'strategies' => [['key' => 'push_archive', 'enabled' => true], ['key' => 'push_rsync', 'enabled' => true], ['key' => 'server_git', 'enabled' => false]],
        ]);
        $projects['atelier']->update([
            'health_url' => 'https://atelier.example.org/', 'health_timeout' => 30, 'shared_paths' => ['storage', 'public/uploads'], 'keep_releases' => 4,
            'ci_state' => CiState::Passing, 'ci_checked_at' => now(),
        ]);
        $projects['atelier']->envFiles()->create(['path' => '.env.local', 'role' => EnvFileRole::App, ...ProjectEnvFile::contentAttributes(
            "APP_ENV=prod\nAPP_SECRET=".bin2hex(random_bytes(16))."\nDATABASE_URL=postgresql://atelier:".bin2hex(random_bytes(10))."@postgres:5432/atelier\nMAILER_DSN=smtp://localhost\n",
        )]);

        $projects['reviews'] = $import($reviews, 'Reviews', [
            'versionable' => false, 'dockerized' => true, 'stack_name' => 'reviews', 'compose_file' => 'compose.yaml',
            'strategies' => [['key' => 'push_archive', 'enabled' => true], ['key' => 'server_git', 'enabled' => false]],
        ]);
        $projects['reviews']->update(['health_url' => 'https://reviews.example.dev/up', 'ci_state' => CiState::Running, 'ci_checked_at' => now()]);
        $projects['reviews']->envFiles()->create(['path' => '.env', 'role' => EnvFileRole::Compose, ...ProjectEnvFile::contentAttributes(
            "APP_NAME=Reviews\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nMEILISEARCH_KEY=".bin2hex(random_bytes(16))."\n",
        )]);

        foreach ($projects as $project) {
            $project->refresh();
        }

        return $projects;
    }

    /**
     * A git repository in the demo home with a history: [days ago, message,
     * file, adds a migration] per commit, oldest first.
     *
     * @param  array<string, string>  $files
     * @param  list<array{0: int, 1: string, 2: string, 3?: bool}>  $commits
     */
    private function repository(string $name, array $files, array $commits): string
    {
        $path = "{$this->home}/Projects/{$name}";
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path);

        foreach ($files as $file => $contents) {
            File::ensureDirectoryExists(dirname("{$path}/{$file}"));
            File::put("{$path}/{$file}", $contents);
        }

        $git = function (Carbon $at, string ...$args) use ($path): void {
            $date = $at->format('Y-m-d\TH:i:sP');
            Process::path($path)->env([
                'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
                'GIT_AUTHOR_NAME' => 'Roman Kozin', 'GIT_AUTHOR_EMAIL' => 'roman@example.com', 'GIT_AUTHOR_DATE' => $date,
                'GIT_COMMITTER_NAME' => 'Roman Kozin', 'GIT_COMMITTER_EMAIL' => 'roman@example.com', 'GIT_COMMITTER_DATE' => $date,
            ])->run(['git', ...$args])->throw();
        };

        $first = $this->now->copy()->subDays(9)->setTime(11, 5);
        $git($first, 'init', '--quiet', '--initial-branch=main');
        $git($first, 'remote', 'add', 'origin', "https://github.com/KielD-01/{$name}.git");
        $git($first, 'add', '--all');
        $git($first, 'commit', '--quiet', '-m', 'First commit');

        foreach ($commits as $i => $commit) {
            [$daysAgo, $message, $file] = $commit;
            $at = $this->now->copy()->subDays($daysAgo)->setTime(9 + $i % 5, ($i * 13) % 60)->min($this->now->copy()->subMinutes(70 - $i));
            File::ensureDirectoryExists(dirname("{$path}/{$file}"));
            File::append("{$path}/{$file}", "// {$message}\n");
            if ($commit[3] ?? false) {
                $migration = "database/migrations/{$at->format('Y_m_d_His')}_".str_replace(' ', '_', strtolower(substr($message, 0, 24))).'.php';
                File::ensureDirectoryExists(dirname("{$path}/{$migration}"));
                File::put("{$path}/{$migration}", "<?php\n");
            }
            $git($at, 'add', '--all');
            $git($at, 'commit', '--quiet', '-m', $message);
        }

        return $path;
    }

    /**
     * A commit nothing has deployed yet: what the next deploy would ship.
     */
    private function commitAfter(Project $project, string $message, string $file, int $minutesAgo): void
    {
        $path = (string) $project->local_path;
        $date = $this->now->copy()->subMinutes($minutesAgo)->format('Y-m-d\TH:i:sP');
        File::ensureDirectoryExists(dirname("{$path}/{$file}"));
        File::append("{$path}/{$file}", "// {$message}\n");

        foreach ([['add', '--all'], ['commit', '--quiet', '-m', $message]] as $args) {
            Process::path($path)->env([
                'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
                'GIT_AUTHOR_NAME' => 'Roman Kozin', 'GIT_AUTHOR_EMAIL' => 'roman@example.com', 'GIT_AUTHOR_DATE' => $date,
                'GIT_COMMITTER_NAME' => 'Roman Kozin', 'GIT_COMMITTER_EMAIL' => 'roman@example.com', 'GIT_COMMITTER_DATE' => $date,
            ])->run(['git', ...$args])->throw();
        }
    }

    /**
     * The commit that was the branch's head at a moment of the history.
     */
    private function commitAt(Project $project, Carbon $at): string
    {
        return trim(Process::path((string) $project->local_path)->env(['GIT_CONFIG_GLOBAL' => '/dev/null'])
            ->run(['git', 'rev-list', '-1', '--before='.$at->format('Y-m-d\TH:i:sP'), 'main'])->throw()->output());
    }

    /**
     * @param  array<string, Server>  $servers
     * @param  array<string, Project>  $projects
     * @return array<string, Target>
     */
    private function targets(array $servers, array $projects): array
    {
        $assign = app(AssignProject::class);

        return [
            'warehouse@edge-fra' => $assign($projects['warehouse'], $servers['edge-fra'], '/opt/srv'),
            'warehouse@stage-ams' => $assign($projects['warehouse'], $servers['stage-ams'], '/opt/srv'),
            'atelier@edge-fra' => $assign($projects['atelier'], $servers['edge-fra'], '/opt/srv'),
            'reviews@build-box' => $assign($projects['reviews'], $servers['build-box'], '/opt/srv'),
        ];
    }

    // ── Runs ────────────────────────────────────────────────────────────

    /**
     * A week of runs, oldest first.
     *
     * @param  array<string, Target>  $targets
     */
    private function timeline(array $targets, User $user): void
    {
        $day = fn (int $daysAgo, string $time) => $this->now->copy()->subDays($daysAgo)->setTimeFromTimeString($time);

        $this->start($targets['warehouse@stage-ams'], DeploymentKind::Bootstrap, $day(6, '09:52'), $user);
        $this->start($targets['warehouse@edge-fra'], DeploymentKind::Bootstrap, $day(6, '09:58'), $user);
        $this->start($targets['atelier@edge-fra'], DeploymentKind::Bootstrap, $day(6, '10:20'), $user);
        $this->start($targets['reviews@build-box'], DeploymentKind::Adopt, $day(6, '10:41'), $user);

        $this->deploy($targets['warehouse@stage-ams'], $day(6, '11:05'), $user);
        $this->deploy($targets['warehouse@edge-fra'], $day(6, '11:20'), $user);
        $this->deploy($targets['atelier@edge-fra'], $day(5, '16:12'), $user);
        $this->deploy($targets['warehouse@stage-ams'], $day(4, '12:31'), null, 'ci');
        $this->deploy($targets['warehouse@edge-fra'], $day(4, '14:02'), $user);
        $this->deploy($targets['reviews@build-box'], $day(3, '10:15'), $user);

        // The health check said no: switched back on its own, no downtime.
        $this->deploy($targets['warehouse@edge-fra'], $day(3, '15:47'), $user, unhealthy: true);
        $this->deploy($targets['atelier@edge-fra'], $day(2, '11:09'), $user, rsyncFails: true);
        $this->start($targets['atelier@edge-fra'], DeploymentKind::Unlock, $day(2, '11:13'), $user);
        $this->deploy($targets['atelier@edge-fra'], $day(2, '11:16'), $user);
        $this->deploy($targets['warehouse@stage-ams'], $day(2, '13:40'), null, 'ci');
        $this->deploy($targets['warehouse@edge-fra'], $day(1, '10:22'), $user);
        $this->deploy($targets['reviews@build-box'], $day(1, '17:05'), $user);
        $this->deploy($targets['warehouse@stage-ams'], $this->now->copy()->subMinutes(55)->startOfMinute(), null, 'ci');
        $this->deploy($targets['atelier@edge-fra'], $this->now->copy()->subMinutes(48)->startOfMinute(), $user);
        $this->deploy($targets['warehouse@edge-fra'], $this->now->copy()->subMinutes(35)->startOfMinute(), $user);
    }

    private function deploy(Target $target, Carbon $at, ?User $user, string $trigger = 'manual', bool $unhealthy = false, bool $rsyncFails = false): void
    {
        $sha = $this->commitAt($target->project, $at);
        $this->ciHead[$target->project->github_repo] = $sha;

        $this->start($target, DeploymentKind::Deploy, $at, $user, ['commit' => $sha, 'backup' => true], $trigger, $unhealthy, $rsyncFails);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function start(Target $target, DeploymentKind $kind, Carbon $at, ?User $user, array $options = [], string $trigger = 'manual', bool $unhealthy = false, bool $rsyncFails = false): Deployment
    {
        Carbon::setTestNow($at);
        $this->calls = [];
        $this->rsyncFails = $rsyncFails;
        $this->scripts($target, $unhealthy);
        // A run CI started is nobody's.
        $user !== null ? Auth::setUser($user) : Auth::forgetUser();

        $deployment = Deployment::query()->create([
            'target_id' => $target->id, 'project_id' => $target->project_id, 'server_id' => $target->server_id,
            'user_id' => $user?->id, 'kind' => $kind, 'trigger' => $trigger, 'options' => $options,
        ]);
        app(PipelineRunner::class)->run($deployment);

        return $deployment->refresh();
    }

    /**
     * What each deploy script prints on a server where things work, taking
     * a realistic while: the clock moves on as it answers.
     */
    private function scripts(Target $target, bool $unhealthy): void
    {
        $project = $target->project;
        $stack = (string) $project->stack_name;
        $took = fn (int $seconds, string $stdout, int $exit = 0) => function () use ($seconds, $stdout, $exit) {
            Carbon::setTestNow(now()->addSeconds($seconds)->addMilliseconds(mt_rand(0, 900)));

            return new SshResult($exit, $stdout, '', (float) $seconds);
        };
        $script = fn (RemoteScript $script, string $name) => $this->var($script, $name);

        $responses = [
            'deploy-preflight' => $took(2, "✓ {$target->base_dir} is writable for deploy\n::deployme::user=deploy\n::deployme::free=264112394240\n✓ 246 GB free on /\n::deployme::gnu_mv=1\n✓ Docker 28.4.0, Compose 2.39.4\n::deployme::docker=28.4.0\n::deployme::compose=2.39.4\n✓ proxy-network is there\n::deployme::ok=1\n"),
            'deploy-lease' => fn (string $alias, RemoteScript $s) => $took(1, "✓ Locked {$target->path} for deploy #{$script($s, 'OWNER')}\n::deployme::lease=acquired\n")(),
            'deploy-bootstrap' => $took(1, "✓ {$target->path}/releases and {$target->path}/shared are ready\n::deployme::layout=".($project->versionable ? 'releases' : 'in_place')."\n"),
            'deploy-write-file' => fn (string $alias, RemoteScript $s) => $took(1, '✓ Wrote '.basename((string) $script($s, 'FILE'))."\n")(),
            'deploy-build-ignore' => $took(1, "✓ .dockerignore keeps the shared paths out of the build\n"),
            'deploy-state' => $took(1, "✓ Wrote .deploy/state.json\n"),
            'deploy-unlock' => fn (string $alias, RemoteScript $s) => $script($s, 'FORCE') === '1'
                ? $took(1, '! Broke the lock deploy #'.(Deployment::query()->where('target_id', $target->id)->latest('id')->skip(1)->value('id'))." held on {$target->path}\n::deployme::broken=1\n::deployme::previous=".(Deployment::query()->where('target_id', $target->id)->latest('id')->skip(1)->value('id'))."\n")()
                : $took(1, "✓ Unlocked {$target->path}\n")(),
            'deploy-link-shared' => fn (string $alias, RemoteScript $s) => $took(1, collect(explode("\n", (string) $script($s, 'DIRS')."\n".$script($s, 'FILES')))->filter()->map(fn ($path) => "✓ {$path} → shared/{$path}")->implode("\n")."\n")(),
            'deploy-hook' => $took(3, "   INFO  Caching framework bootstrap, configuration, and metadata.\n\n  config ......................................... 24.61ms DONE\n  events .......................................... 1.20ms DONE\n  routes ......................................... 31.84ms DONE\n  views ......................................... 412.07ms DONE\n"),
            'deploy-activate' => fn (string $alias, RemoteScript $s) => $took(1, "✓ current → releases/{$script($s, 'RELEASE')}\n::deployme::was=\n")(),
            'deploy-health' => $took(3, "… HTTP 502, trying again in 3 s\n✓ {$project->health_url} answered 200 after 3 s (asked this server's edge)\n::deployme::code=200\n::deployme::after=3\n::deployme::via=edge\n"),
            'deploy-prune' => $took(2, "✓ Removed one release beyond the newest {$project->keep_releases}\n"),
            'deploy-prune-images' => $took(4, "Untagged: ghcr.io/kield-01/{$stack}-api:sha-4e1a9c2\nDeleted: sha256:7d0be1e6b25c\n✓ Removed 2 images no release here needs\n::deployme::removed=2\n::deployme::kept=0\n::deployme::gone=0\n"),
            'deploy-db-backup' => $took(6, "\$ pg_dump -U warehouse warehouse\n✓ warehouse-{$this->stamp()}.dump (18.4 MB)\n::deployme::file=warehouse-{$this->stamp()}.dump\n::deployme::size=19293184\n"),
            'deploy-inspect' => $took(3, "✓ 14 item(s) in {$target->path}\n✓ A git checkout at 3f9c2d1 on main\n::deployme::commit=".$this->commitAt($project, now()->subDays(6))."\n::deployme::branch=main\n::deployme::subject=Reply to a review from the admin\n::deployme::stack=running(3)\n::deployme::files=14\n"),
            'deploy-registry-login' => $took(2, "Login Succeeded\n✓ Logged in to ghcr.io for this deploy only\n"),
            'deploy-forget-login' => $took(1, "✓ Forgot this deploy's registry login\n"),
            'deploy-kill' => $took(1, "· Nothing of it was still running\n"),
            'deploy-sync-in-place' => fn (string $alias, RemoteScript $s) => $took(4, "✓ Synced release {$script($s, 'RELEASE')} into {$target->path}: 23 file(s) updated, 2 removed\n::deployme::updated=23\n::deployme::deleted=2\n")(),
            'deploy-compose' => fn (string $alias, RemoteScript $s) => $this->compose($s, $stack, $took),
            'deploy-compose-roll' => fn (string $alias, RemoteScript $s) => $this->roll($s, $stack, $took),
            'deploy-compose-health' => function (string $alias, RemoteScript $s) use ($took, $unhealthy, $stack) {
                $n = $this->calls['compose-health'] = ($this->calls['compose-health'] ?? 0) + 1;
                $services = collect(explode("\n", (string) $this->var($s, 'SERVICES')))->filter()->values();

                if ($unhealthy && $n === 1) {
                    return $took(60, "Waiting for {$services->implode(' ')} in stack {$stack} (up to 60 s)\napi running, starting\nweb running, healthy\napi running, unhealthy\n✗ After 60 s: api (running, unhealthy)\n::deployme::waiting=api (running, unhealthy)\n", 30)();
                }

                return $took(7, "Waiting for {$services->implode(' ')} in stack {$stack} (up to 60 s)\n".$services->map(fn ($service) => "{$service} running, starting")->implode("\n")."\n".$services->map(fn ($service) => "{$service} running, healthy")->implode("\n")."\n✓ Everything is up after 7 s\n::deployme::after=7\n")();
            },
        ];

        foreach ($responses as $name => $response) {
            $this->ssh->on($target->server->ssh_alias, $name, $response);
        }
    }

    private function compose(RemoteScript $script, string $stack, Closure $took): SshResult
    {
        $services = collect(explode("\n", (string) $this->var($script, 'SERVICES')))->filter()->values();

        return match ($this->var($script, 'ACTION')) {
            'config' => $took(2, "\$ docker compose -p {$stack} config --quiet\n✓ Compose reads the stack from this release: 4 service(s)\n::deployme::services=4\n")(),
            'pull' => $took(19, "\$ docker compose -p {$stack} pull {$services->implode(' ')}\n".$services->map(fn ($service) => " {$service} Pulling")->implode("\n")."\n 7d0be1e6b25c Pull complete\n a1f3e9c04b88 Pull complete\n".$services->map(fn ($service) => " {$service} Pulled")->implode("\n")."\n")(),
            'build' => $took(84, "\$ docker compose -p {$stack} build app\n#1 [internal] load build definition from Dockerfile\n#5 [2/4] COPY . /app\n#5 DONE 0.4s\n#7 [4/4] RUN composer install --no-dev --optimize-autoloader\n#7 DONE 41.2s\n#8 exporting to image\n#8 naming to docker.io/library/{$stack}-app:local done\n")(),
            'exec' => $took(3, "\$ docker compose -p {$stack} exec api php artisan optimize\n\n   INFO  Caching framework bootstrap, configuration, and metadata.\n\n  config ......................................... 24.61ms DONE\n  routes ......................................... 31.84ms DONE\n  views ......................................... 412.07ms DONE\n")(),
            'run' => $took(9, "\$ docker compose -p {$stack} run --rm migrate php artisan migrate --force\n\n   INFO  Running migrations.\n\n  2026_10_14_101500_reserve_stock_on_orders ............ 38.17ms DONE\n  2026_10_16_120900_batch_inventory_recounts ........... 21.42ms DONE\n\n")(),
            default => $took(11, "\$ docker compose -p {$stack} up -d\n Container {$stack}-queue-1  Recreate\n Container {$stack}-queue-1  Recreated\n Container {$stack}-queue-1  Starting\n Container {$stack}-queue-1  Started\n✓ up -d replaced queue\n")(),
        };
    }

    private function roll(RemoteScript $script, string $stack, Closure $took): SshResult
    {
        $color = (string) $this->var($script, 'COLOR');

        return $took(21, "  api: 2 running\n  web: 1 running\n\$ docker compose -p {$stack} up -d --no-deps --no-recreate --scale api=4 --scale web=2 api web\n Container {$stack}-api-5  Started\n Container {$stack}-api-6  Started\n Container {$stack}-web-3  Started\n✓ 3 {$color} containers started beside 3 still serving\n✓ The {$color} containers are healthy after 9 s\n✓ Retired 3 old containers\n✓ {$stack}-api-5 is now api-{$color}-1\n✓ {$stack}-api-6 is now api-{$color}-2\n✓ {$stack}-web-3 is now web-{$color}\n::deployme::started=3\n::deployme::retired=3\n::deployme::color={$color}\n::deployme::rolled=api,web\n")();
    }

    // ── Health, folders ─────────────────────────────────────────────────

    /**
     * A check every ten minutes over the last half day; atelier flaps once.
     *
     * @param  array<string, Target>  $targets
     */
    private function healthHistory(array $targets): void
    {
        Auth::forgetUser();

        $base = $this->now->copy();
        for ($at = $base->copy()->subHours(12); $at->lt($base); $at = $at->copy()->addMinutes(10)) {
            foreach ($targets as $key => $target) {
                Carbon::setTestNow($at);
                $flapping = $key === 'atelier@edge-fra' && $at->between($base->copy()->subMinutes(115), $base->copy()->subMinutes(95));
                $this->ssh->on($target->server->ssh_alias, 'deploy-health', $flapping
                    ? new SshResult(30, "✗ {$target->project->health_url} gave HTTP 502 for 5 s\n::deployme::last=HTTP 502\n", '', 5.0)
                    : FakeSshClient::ok("✓ answered 200 after 0 s\n::deployme::code=200\n::deployme::after=0\n::deployme::via=edge\n"));
                $this->ssh->on($target->server->ssh_alias, 'deploy-compose-health', FakeSshClient::ok("✓ Everything is up after 0 s\n::deployme::after=0\n"));
                app(CheckTargetHealth::class)($target->refresh());
            }
        }
    }

    private function folderSync(Server $server, User $user): void
    {
        Auth::setUser($user);
        $local = "{$this->home}/Projects/reviews/storage/uploads";
        foreach (['covers/2026-10/oak-desk.jpg', 'covers/2026-10/linen-chair.jpg', 'avatars/u1842.png', 'exports/october.csv'] as $file) {
            File::ensureDirectoryExists(dirname("{$local}/{$file}"));
            File::put("{$local}/{$file}", random_bytes(2048));
        }

        Carbon::setTestNow($this->now->copy()->subHours(5));
        $mapping = FolderMapping::query()->create([
            'server_id' => $server->id, 'name' => 'Review photos', 'direction' => SyncDirection::Push, 'local_path' => $local,
            'remote_path' => '/opt/srv/reviews/storage/app/public/uploads', 'excludes' => ['*.tmp', '.DS_Store'], 'delete_extraneous' => false,
            'schedule_interval' => 3600, 'next_run_at' => now()->addHour(),
        ]);

        $this->ssh->on($server->ssh_alias, '*', FakeSshClient::ok("✓ done\n"));
        $start = app(StartFolderSync::class);
        foreach ([true, false] as $dryRun) {
            $run = $start($mapping, dryRun: $dryRun, user: $user);
            app(RunFolderSync::class)($run);
            Carbon::setTestNow(now()->addMinutes(3));
        }
    }

    // ── Fakes ───────────────────────────────────────────────────────────

    /**
     * What rsync reports: itemized changes, then its statistics.
     *
     * @param  list<string>|string  $command
     */
    private function rsync(array|string $command): mixed
    {
        Carbon::setTestNow(now()->addSeconds(6));

        if ($this->rsyncFails) {
            return Process::result('', "rsync: [receiver] write failed on \"public/uploads/lookbook.mp4\": No space left on device (28)\nrsync error: error in file IO (code 11) at receiver.c(381) [receiver=3.2.7]\n", 11);
        }

        $changes = "cd+++++++++ 4096 covers/2026-10/\n<f+++++++++ 1482752 covers/2026-10/oak-desk.jpg\n<f+++++++++ 1310720 covers/2026-10/linen-chair.jpg\n<f.st...... 389120 exports/october.csv\n<f+++++++++ 2048 avatars/u1842.png\n";

        return Process::result($changes."\nNumber of files: 312 (reg: 287, dir: 25)\nNumber of created files: 3\nNumber of deleted files: 0\nNumber of regular files transferred: 41\nTotal file size: 48213376 bytes\nTotal transferred file size: 3184640 bytes\n");
    }

    /**
     * GitHub's API for the demo repositories: CI passed on every commit, and
     * the images it published are there.
     */
    private function github(Request $request): mixed
    {
        if (preg_match('~/repos/KielD-01/([^/]+)/(.*)$~', parse_url($request->url(), PHP_URL_PATH) ?? '', $match) !== 1) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        [, $repo, $rest] = $match;
        $sha = $this->ciHead[$repo] ?? str_repeat('0', 40);
        $subject = trim(Process::path("{$this->home}/Projects/{$repo}")->env(['GIT_CONFIG_GLOBAL' => '/dev/null'])->run(['git', 'log', '-1', '--format=%s', $sha])->output());
        $run = [
            'id' => 1844, 'name' => 'CI/CD', 'path' => '.github/workflows/ci-cd.yml', 'run_number' => 412, 'workflow_id' => 7,
            'status' => 'completed', 'conclusion' => 'success', 'event' => 'push', 'head_sha' => $sha, 'head_branch' => 'main',
            'html_url' => "https://github.com/KielD-01/{$repo}/actions/runs/1844", 'updated_at' => now()->subMinutes(4)->toIso8601String(),
            'head_commit' => ['message' => $subject],
        ];

        return match (true) {
            str_contains($rest, '/jobs') => Http::response(['jobs' => [
                ['name' => 'Tests', 'status' => 'completed', 'conclusion' => 'success', 'started_at' => now()->subMinutes(9)->toIso8601String(), 'completed_at' => now()->subMinutes(6)->toIso8601String()],
                ['name' => 'Images (api)', 'status' => 'completed', 'conclusion' => 'success', 'started_at' => now()->subMinutes(6)->toIso8601String(), 'completed_at' => now()->subMinutes(4)->toIso8601String()],
            ]]),
            str_starts_with($rest, 'actions/runs') => Http::response(['total_count' => 1, 'workflow_runs' => [$run]]),
            str_starts_with($rest, 'commits') => Http::response([['sha' => $sha, 'commit' => ['message' => $subject, 'committer' => ['date' => now()->toIso8601String()]]]]),
            str_starts_with($rest, 'branches') => Http::response(['name' => 'main', 'commit' => ['sha' => $sha]]),
            str_starts_with($rest, 'compare') => Http::response(['status' => 'ahead', 'ahead_by' => 2, 'behind_by' => 0]),
            default => Http::response(['message' => 'Not Found'], 404),
        };
    }

    private function var(RemoteScript $script, string $name): ?string
    {
        return preg_match('/^'.preg_quote($name, '/')."=\\$\\(printf %s '([^']*)'/m", $script->render(), $match) === 1 ? base64_decode($match[1]) : null;
    }

    private function stamp(): string
    {
        return now()->utc()->format('Ymd-His');
    }
}
