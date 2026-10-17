<?php

use App\Actions\Servers\CheckRoute;
use App\Enums\RouteKind;
use App\Enums\RouteState;
use App\Models\Server;
use App\Platform\Ssh\Exceptions\HostUnreachable;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;
use App\Platform\Ssh\SshTargetResolver;
use Illuminate\Support\Facades\Process;

// Real ssh to the local sandbox (`make sandbox`) through a route: a SOCKS5 or
// HTTP proxy (tests/Fixtures/fake-proxy.php, forwarding for real) or a jump
// host. Skipped when the sandbox isn't running.
beforeEach(function () {
    if (gethostbyname('deployme-sshd-target') === 'deployme-sshd-target' || ! is_readable(storage_path('app/sandbox-ssh/ssh_config'))) {
        $this->markTestSkipped('The sandbox isn’t running: `make sandbox`.');
    }

    $this->runtime = sys_get_temp_dir().'/deployme-it-'.bin2hex(random_bytes(4));
    mkdir($this->runtime, 0700, true);
    // A second name for the sandbox, to reach it through the first one.
    file_put_contents("{$this->runtime}/hop.conf", "Host sandbox-hop\n  HostName deployme-sshd-target\n  User deploy\n  IdentityFile ".storage_path('app/sandbox-ssh/id_ed25519')."\n  IdentitiesOnly yes\n");

    config([
        'deployme.ssh.runtime_dir' => "{$this->runtime}/run",
        'deployme.ssh.user_known_hosts' => '/nonexistent',
        'deployme.ssh.extra_configs' => [storage_path('app/sandbox-ssh/ssh_config'), "{$this->runtime}/hop.conf"],
    ]);

    $this->app->singleton(SshConfigCompiler::class, fn () => SshConfigCompiler::fromConfig());
    $this->app->singleton(KnownHosts::class);
    $this->app->singleton(SshTargetResolver::class);
    $this->app->singleton(SshClient::class, ProcessSshClient::class);
    app(KnownHosts::class)->trust(app(KnownHosts::class)->scan('sandbox'), null);
});

afterEach(function () {
    if (isset($this->proxy)) {
        stopFakeProxy($this->proxy);
    }
    if (isset($this->runtime)) {
        exec('rm -rf '.escapeshellarg($this->runtime));
    }
});

function routedSandbox(string $kind, int $port, ?string $user = null, ?string $password = null): Server
{
    return Server::factory()->create([
        'ssh_alias' => 'sandbox', 'name' => 'sandbox',
        'route_kind' => RouteKind::from($kind), 'route' => ['host' => '127.0.0.1', 'port' => $port, 'user' => $user], 'route_secret' => $password,
    ]);
}

it('runs ssh, rsync and the route check through a SOCKS5 proxy that wants a password', function () {
    $this->proxy = fakeProxy('socks5', 'deploy', 'pr0xy-secret');
    $server = routedSandbox('socks5', $this->proxy['port'], 'deploy', 'pr0xy-secret');

    $ssh = app(SshClient::class);
    expect(trim($ssh->run('sandbox', RemoteScript::inline('t', 'echo through-the-proxy'))->throw()->stdout))->toBe('through-the-proxy');

    // rsync rides the same ssh -F config.
    $folder = "{$this->runtime}/upload";
    mkdir($folder);
    file_put_contents("{$folder}/hello.txt", 'hello');
    $config = app(SshConfigCompiler::class)->compile();
    $rsync = Process::run(['rsync', '-a', '--rsh=ssh -F '.$config, "{$folder}/", 'sandbox:/tmp/route-it/']);
    expect($rsync->successful())->toBeTrue($rsync->errorOutput())
        ->and(trim($ssh->run('sandbox', RemoteScript::inline('t', 'cat /tmp/route-it/hello.txt; rm -rf /tmp/route-it'))->throw()->stdout))->toBe('hello');

    $check = app(CheckRoute::class)($server, 'manual');
    expect($check->state)->toBe(RouteState::Up, (string) $check->detail)
        ->and($check->detail)->toContain('SOCKS5 127.0.0.1:'.$this->proxy['port'].' → deployme-sshd-target:22 answered SSH-2.0-OpenSSH');

    // The password stays in the 0600 route file: not in the config ssh reads.
    expect(file_get_contents($config))->not->toContain('pr0xy-secret');
})->group('ssh-integration');

it('goes through an HTTP proxy too', function () {
    $this->proxy = fakeProxy('http');
    routedSandbox('http', $this->proxy['port']);

    expect(trim(app(SshClient::class)->run('sandbox', RemoteScript::inline('t', 'echo via-http'))->throw()->stdout))->toBe('via-http');
})->group('ssh-integration');

it('says which hop failed when the proxy is gone', function () {
    $this->proxy = fakeProxy('socks5');
    $server = routedSandbox('socks5', $this->proxy['port']);
    stopFakeProxy($this->proxy);
    unset($this->proxy);

    $result = app(SshClient::class)->run('sandbox', RemoteScript::inline('t', 'true'));
    expect(fn () => $result->throw())->toThrow(HostUnreachable::class, 'refused the connection');

    expect(app(CheckRoute::class)($server, 'manual')->state)->toBe(RouteState::RouteDown);
})->group('ssh-integration');

it('reaches a server through a jump host', function () {
    $server = Server::factory()->create(['ssh_alias' => 'sandbox-hop', 'route_kind' => RouteKind::Jump, 'route' => ['jump' => 'sandbox']]);

    expect(trim(app(SshClient::class)->run('sandbox-hop', RemoteScript::inline('t', 'echo hopped'))->throw()->stdout))->toBe('hopped');

    $check = app(CheckRoute::class)($server, 'manual');
    expect($check->state)->toBe(RouteState::Up, (string) $check->detail)
        ->and($check->detail)->toStartWith('jump host sandbox → deployme-sshd-target:22 answered SSH-2.0-');
})->group('ssh-integration');
