<?php

namespace App\Platform\Ssh;

use App\Enums\RouteKind;
use App\Models\Server;
use App\Platform\Ssh\Route\Route;
use Closure;
use RuntimeException;

/**
 * Builds the ssh config every ssh call in deploy.me uses (`ssh -F <path>`).
 *
 * Enforced options come first because ssh keeps the first value it reads for
 * each option, and ProxyJump hops inherit `-F` but not `-o`. Then the routes
 * set on deploy.me's server pages (a ProxyJump, or a ProxyCommand through a
 * proxy), so they win over the user's config, then the user's own config,
 * included as-is (read-only mount), then extra configs such as the local
 * sandbox, then defaults that only apply where the user set nothing.
 *
 * A proxy's address and password go to a 0600 file per server in the
 * memory-only runtime folder, which the ProxyCommand helper reads: argv
 * carries only that file's path.
 */
final class SshConfigCompiler
{
    /**
     * @param  list<string>  $extraConfigs
     */
    public function __construct(
        private readonly string $sourceConfig,
        private readonly array $extraConfigs,
        private readonly string $runtimeDir,
        private readonly string $userKnownHosts,
        private readonly ?string $agentSocket,
        private readonly ?string $defaultUser,
        private readonly int $connectTimeout = 10,
        // fn () => iterable<array{id: int, alias: string, route: Route}>
        private readonly ?Closure $routes = null,
        private readonly string $phpBinary = 'php',
        private readonly string $proxyScript = '',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('deployme.ssh.source_config'),
            config('deployme.ssh.extra_configs', []),
            rtrim((string) config('deployme.ssh.runtime_dir'), '/'),
            (string) config('deployme.ssh.user_known_hosts'),
            config('deployme.ssh.agent_socket') ?: null,
            config('deployme.host_user') ?: null,
            (int) config('deployme.ssh.connect_timeout', 10),
            fn () => rescue(fn () => Server::query()->active()
                ->where('route_kind', '!=', RouteKind::Direct->value)
                ->get()
                ->map(fn (Server $server) => ['id' => $server->id, 'alias' => $server->ssh_alias, 'route' => $server->connectionRoute()])
                ->all(), [], report: false),
            (string) config('deployme.ssh.php_binary', 'php'),
            resource_path('bin/deployme-ssh-proxy.php'),
        );
    }

    public function routeFile(int $serverId): string
    {
        return $this->runtimeDir.'/routes/'.$serverId.'.json';
    }

    /**
     * Why the proxy helper last failed for this alias, if it did in the last
     * two minutes. ssh hides a ProxyCommand's stderr once ControlPersist is
     * on, so the helper leaves the line in <id>.err instead.
     */
    public function routeError(string $alias): ?string
    {
        foreach ($this->routes() as $row) {
            if ($row['alias'] !== $alias) {
                continue;
            }

            $file = $this->runtimeDir.'/routes/'.$row['id'].'.err';

            return is_file($file) && filemtime($file) >= time() - 120 ? (trim((string) file_get_contents($file)) ?: null) : null;
        }

        return null;
    }

    public function path(): string
    {
        return $this->runtimeDir.'/config';
    }

    public function knownHostsPath(): string
    {
        return $this->runtimeDir.'/known_hosts';
    }

    /**
     * Compiles when a source changed (or on first use) and returns the config path.
     */
    public function compile(bool $force = false): string
    {
        $routes = $this->routes();
        $contents = $this->render($routes);
        $files = $this->routeFiles($routes);
        $hashFile = $this->runtimeDir.'/.hash';
        $hash = hash('xxh128', $contents.$this->sourceFingerprint().hash('xxh128', serialize($files)));

        if (! $force && is_file($this->path()) && @file_get_contents($hashFile) === $hash) {
            return $this->path();
        }

        foreach ([$this->runtimeDir, $this->runtimeDir.'/cm', $this->runtimeDir.'/routes'] as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException("Can't create {$dir}.");
            }
        }

        if (! is_file($this->knownHostsPath())) {
            $this->write($this->knownHostsPath(), '');
        }

        // The proxy files first, so the config never names one that isn't there.
        foreach ($files as $path => $json) {
            $this->write($path, $json);
        }
        foreach ([...glob($this->runtimeDir.'/routes/*.json') ?: [], ...glob($this->runtimeDir.'/routes/*.err') ?: []] as $stale) {
            if (! isset($files[preg_replace('/\.err$/', '.json', $stale)])) {
                @unlink($stale);
            }
        }

        $this->write($this->path(), $contents);
        $this->write($hashFile, $hash);

        return $this->path();
    }

    /**
     * @param  list<array{id: int, alias: string, route: Route}>|null  $routes
     */
    public function render(?array $routes = null): string
    {
        $routes ??= $this->routes();
        $knownHosts = $this->knownHostsPath().(is_readable($this->userKnownHosts) ? ' '.$this->userKnownHosts : '');

        $lines = [
            '# Generated by deploy.me. Do not edit: it is rebuilt from the sources below.',
            // macOS-only keywords in the user's config would otherwise stop Linux ssh.
            'IgnoreUnknown UseKeychain,AddKeysToAgent,EnableEscapeCommandline',
            '',
            'Host *',
            '  BatchMode yes',
            '  StrictHostKeyChecking yes',
            "  UserKnownHostsFile {$knownHosts}",
            '  GlobalKnownHostsFile /dev/null',
            '  UpdateHostKeys no',
            '  HashKnownHosts no',
            '  CheckHostIP no',
            '  IdentityAgent '.($this->agentSocket ?: 'none'),
            '  ForwardAgent no',
            '  ForwardX11 no',
            '  ClearAllForwardings yes',
            '  PermitLocalCommand no',
            '  RequestTTY no',
            '  ControlMaster auto',
            "  ControlPath {$this->runtimeDir}/cm/%C",
            '  ControlPersist 150s',
            '  LogLevel ERROR',
            '',
        ];

        $routed = false;

        foreach ($routes as ['id' => $id, 'alias' => $alias, 'route' => $route]) {
            $line = match ($route->kind) {
                RouteKind::Jump => "  ProxyJump {$route->jump}",
                RouteKind::Socks5, RouteKind::Http => '  ProxyCommand '.escapeshellarg($this->phpBinary).' '.escapeshellarg($this->proxyScript).' '.escapeshellarg($this->routeFile($id)).' %h %p',
                default => null,
            };

            if ($line !== null) {
                array_push($lines, "# The route set on deploy.me's page for {$alias}: {$route->kind->value}.", "Host {$alias}", $line, '');
                $routed = true;
            }
        }

        // An Include inside a Host block only applies to that host: back to
        // every host before the user's config.
        if ($routed) {
            $lines[] = 'Host *';
        }

        foreach ([$this->sourceConfig, ...$this->extraConfigs] as $file) {
            if (is_readable($file)) {
                $lines[] = "Include {$file}";
            }
        }

        $lines[] = '';
        $lines[] = 'Host *';
        if ($this->defaultUser) {
            $lines[] = "  User {$this->defaultUser}";
        }
        $lines[] = "  ConnectTimeout {$this->connectTimeout}";
        $lines[] = '  ServerAliveInterval 15';
        $lines[] = '  ServerAliveCountMax 4';

        return implode("\n", $lines)."\n";
    }

    /**
     * The routes that change how ssh connects, valid ones only: a route that
     * fails its own rules is left out rather than breaking every ssh call.
     *
     * @return list<array{id: int, alias: string, route: Route}>
     */
    private function routes(): array
    {
        if ($this->routes === null) {
            return [];
        }

        return collect(($this->routes)())
            ->filter(fn (array $row) => SshAlias::isValid($row['alias']) && $row['route']->problem($row['alias']) === null)
            ->sortBy('alias')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id: int, alias: string, route: Route}>  $routes
     * @return array<string, string> path => contents
     */
    private function routeFiles(array $routes): array
    {
        $files = [];

        foreach ($routes as ['id' => $id, 'route' => $route]) {
            if ($route->kind->isProxy()) {
                $files[$this->routeFile($id)] = (string) json_encode($route->proxyFile($this->connectTimeout));
            }
        }

        return $files;
    }

    private function sourceFingerprint(): string
    {
        $parts = [];
        foreach ([$this->sourceConfig, ...$this->extraConfigs] as $file) {
            $parts[] = $file.':'.(is_file($file) ? filemtime($file).':'.filesize($file) : 'missing');
        }

        return implode('|', $parts);
    }

    private function write(string $path, string $contents): void
    {
        $tmp = $path.'.'.getmypid().'.tmp';
        file_put_contents($tmp, $contents);
        chmod($tmp, 0600);
        rename($tmp, $path);
    }
}
