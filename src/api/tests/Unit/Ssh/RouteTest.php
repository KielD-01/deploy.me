<?php

use App\Enums\RouteKind;
use App\Platform\Ssh\Route\ProxyTunnel;
use App\Platform\Ssh\Route\Route;
use App\Platform\Ssh\SshConfigCompiler;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/deployme-route-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0700, true);
    file_put_contents($this->dir.'/config', "Host prod\n  HostName 203.0.113.10\n  ProxyJump old-bastion\nHost office\n  HostName 192.168.40.12\n");
});

afterEach(function () {
    if (isset($this->proxy)) {
        stopFakeProxy($this->proxy);
    }
    exec('rm -rf '.escapeshellarg($this->dir));
});

function routedCompiler(string $dir, array $routes): SshConfigCompiler
{
    return new SshConfigCompiler("{$dir}/config", [], "{$dir}/run", "{$dir}/known_hosts", null, 'roman', 10,
        fn () => $routes, '/usr/local/bin/php', '/app/resources/bin/deployme-ssh-proxy.php');
}

it('writes a route ahead of the user’s config, so it wins over theirs', function () {
    $config = routedCompiler($this->dir, [
        ['id' => 7, 'alias' => 'prod', 'route' => new Route(RouteKind::Jump, jump: 'bastion')],
        ['id' => 8, 'alias' => 'office', 'route' => new Route(RouteKind::Socks5, host: '10.0.0.2', port: 1080, user: 'deploy', password: 'pr0xy-secret')],
        ['id' => 9, 'alias' => 'vpn-box', 'route' => new Route(RouteKind::Vpn, checkHost: '10.8.0.1', checkPort: 53)],
    ])->render();

    expect($config)->toContain("Host prod\n  ProxyJump bastion")
        ->toContain("Host office\n  ProxyCommand '/usr/local/bin/php' '/app/resources/bin/deployme-ssh-proxy.php' '{$this->dir}/run/routes/8.json' %h %p")
        ->not->toContain('pr0xy-secret')
        ->not->toContain('Host vpn-box')
        ->and(strpos($config, 'Host prod'))->toBeLessThan(strpos($config, "Include {$this->dir}/config"))
        ->and(strpos($config, 'BatchMode yes'))->toBeLessThan(strpos($config, 'Host prod'));
});

it('keeps a proxy’s password in a private file of its own, and drops the file with the route', function () {
    $route = ['id' => 8, 'alias' => 'office', 'route' => new Route(RouteKind::Socks5, host: '10.0.0.2', port: 1080, user: 'deploy', password: 'pr0xy-secret')];
    routedCompiler($this->dir, [$route])->compile();
    $file = "{$this->dir}/run/routes/8.json";

    expect(substr(sprintf('%o', fileperms($file)), -4))->toBe('0600')
        ->and(json_decode(file_get_contents($file), true))->toMatchArray(['kind' => 'socks5', 'host' => '10.0.0.2', 'port' => 1080, 'user' => 'deploy', 'password' => 'pr0xy-secret'])
        ->and(file_get_contents("{$this->dir}/run/config"))->not->toContain('pr0xy-secret');

    routedCompiler($this->dir, [])->compile();

    expect(is_file($file))->toBeFalse()
        ->and(file_get_contents("{$this->dir}/run/config"))->not->toContain('Host office');
});

it('rewrites the files when only the password changed', function () {
    $route = fn (string $password) => [['id' => 8, 'alias' => 'office', 'route' => new Route(RouteKind::Http, host: 'proxy.example', port: 3128, user: 'u', password: $password)]];
    routedCompiler($this->dir, $route('one'))->compile();
    routedCompiler($this->dir, $route('two'))->compile();

    expect(json_decode(file_get_contents("{$this->dir}/run/routes/8.json"), true)['password'])->toBe('two');
});

it('leaves out a route that breaks its own rules rather than every ssh call', function () {
    $config = routedCompiler($this->dir, [
        ['id' => 1, 'alias' => 'prod', 'route' => new Route(RouteKind::Jump, jump: '-oProxyCommand=evil')],
        ['id' => 2, 'alias' => 'office', 'route' => new Route(RouteKind::Socks5, host: 'bad host', port: 1080)],
    ])->render();

    expect($config)->not->toContain('Host prod')->not->toContain('Host office')->not->toContain('evil');
});

it('is valid for the ssh on this machine, with the route ssh -G resolves', function () {
    $path = routedCompiler($this->dir, [['id' => 7, 'alias' => 'prod', 'route' => new Route(RouteKind::Jump, jump: 'bastion')]])->compile();

    exec('ssh -F '.escapeshellarg($path).' -G prod 2>&1', $output, $code);

    expect($code)->toBe(0)->and(implode("\n", $output))->toContain('proxyjump bastion')->not->toContain('old-bastion');
})->skip(fn () => trim((string) shell_exec('command -v ssh')) === '', 'ssh is not installed');

it('still reads the user’s config for every other host', function () {
    $path = routedCompiler($this->dir, [['id' => 7, 'alias' => 'prod', 'route' => new Route(RouteKind::Jump, jump: 'bastion')]])->compile();

    exec('ssh -F '.escapeshellarg($path).' -G office 2>&1', $output, $code);

    expect($code)->toBe(0)->and(implode("\n", $output))->toContain('hostname 192.168.40.12')->not->toContain('proxyjump');
})->skip(fn () => trim((string) shell_exec('command -v ssh')) === '', 'ssh is not installed');

it('says what is wrong with a route', function (Route $route, ?string $field) {
    expect($route->problem('prod')[0] ?? null)->toBe($field);
})->with([
    'a jump alias' => [new Route(RouteKind::Jump, jump: 'bastion'), null],
    'user@host:port' => [new Route(RouteKind::Jump, jump: 'ops@10.0.0.1:2222'), null],
    'an option in disguise' => [new Route(RouteKind::Jump, jump: '-oProxyCommand=x'), 'routeJump'],
    'two hops' => [new Route(RouteKind::Jump, jump: 'a,b'), 'routeJump'],
    'itself' => [new Route(RouteKind::Jump, jump: 'prod'), 'routeJump'],
    'a proxy' => [new Route(RouteKind::Socks5, host: 'host.docker.internal', port: 1080), null],
    'an IPv6 proxy' => [new Route(RouteKind::Http, host: '[2001:db8::1]', port: 3128), null],
    'no proxy host' => [new Route(RouteKind::Socks5, port: 1080), 'routeHost'],
    'a shell in the host' => [new Route(RouteKind::Socks5, host: 'x;reboot', port: 1080), 'routeHost'],
    'port 0' => [new Route(RouteKind::Http, host: 'proxy', port: 0), 'routePort'],
    'a password without a user' => [new Route(RouteKind::Socks5, host: 'proxy', port: 1080, password: 'p'), 'routeUser'],
    'a VPN with nothing to check' => [new Route(RouteKind::Vpn), null],
    'a VPN check without a port' => [new Route(RouteKind::Vpn, checkHost: '10.8.0.1'), 'routeCheckPort'],
]);

it('reaches a server through a SOCKS5 proxy, signing in', function () {
    $this->proxy = fakeProxy('socks5', 'deploy', 's3cret');
    $socket = ProxyTunnel::open('socks5', '127.0.0.1', $this->proxy['port'], 'deploy', 's3cret', 'banner.test', 22, 3);

    expect(ProxyTunnel::banner($socket, 3))->toBe('SSH-2.0-FakeProxyServer');
});

it('reaches a server through an HTTP proxy', function () {
    $this->proxy = fakeProxy('http', 'deploy', 's3cret');
    $socket = ProxyTunnel::open('http', '127.0.0.1', $this->proxy['port'], 'deploy', 's3cret', 'banner.test', 22, 3);

    expect(ProxyTunnel::banner($socket, 3))->toBe('SSH-2.0-FakeProxyServer');
});

it('tells the proxy letting it down from the server not answering', function (string $kind, ?string $password, string $host, int $code, string $message) {
    $this->proxy = fakeProxy($kind, 'deploy', 's3cret');

    expect(fn () => ProxyTunnel::open($kind, '127.0.0.1', $this->proxy['port'], 'deploy', $password, $host, 1, 3))
        ->toThrow(fn (RuntimeException $e) => expect($e->getCode())->toBe($code)->and($e->getMessage())->toContain($message));
})->with([
    'a wrong SOCKS5 password' => ['socks5', 'nope', 'banner.test', ProxyTunnel::PROXY_DOWN, 'turned down the user and password'],
    'a wrong HTTP password' => ['http', 'nope', 'banner.test', ProxyTunnel::PROXY_DOWN, 'turned down the user and password'],
    'SOCKS5, the server refusing' => ['socks5', 's3cret', '127.0.0.1', ProxyTunnel::SERVER_DOWN, 'connection refused'],
    'HTTP, the server refusing' => ['http', 's3cret', '127.0.0.1', ProxyTunnel::SERVER_DOWN, 'HTTP 502'],
]);

it('says so when nothing answers where the proxy should be', function () {
    expect(fn () => ProxyTunnel::open('socks5', '127.0.0.1', 1, null, null, 'banner.test', 22, 2))
        ->toThrow(RuntimeException::class, 'The SOCKS5 proxy at 127.0.0.1:1 refused the connection.');
});

it('runs as ssh’s ProxyCommand: the password from its file, the bytes both ways', function () {
    $this->proxy = fakeProxy('socks5', 'deploy', 's3cret');
    $file = "{$this->dir}/route.json";
    file_put_contents($file, json_encode((new Route(RouteKind::Socks5, host: '127.0.0.1', port: $this->proxy['port'], user: 'deploy', password: 's3cret'))->proxyFile(3)));

    $helper = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/resources/bin/deployme-ssh-proxy.php', $file, 'banner.test', '22'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    $banner = fgets($pipes[1]);
    fwrite($pipes[0], "hello through the tunnel\n");
    $echo = fgets($pipes[1]);
    fclose($pipes[0]);
    proc_close($helper);

    expect(trim($banner))->toBe('SSH-2.0-FakeProxyServer')->and($echo)->toBe("hello through the tunnel\n");
});

it('fails as a ProxyCommand with one line ssh can pass on', function () {
    $file = "{$this->dir}/route.json";
    file_put_contents($file, json_encode((new Route(RouteKind::Http, host: '127.0.0.1', port: 1))->proxyFile(2)));

    $helper = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/resources/bin/deployme-ssh-proxy.php', $file, 'server', '22'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    $stderr = stream_get_contents($pipes[2]);
    $code = proc_close($helper);

    // ssh hides a ProxyCommand's stderr under ControlPersist: the reason is in a file too.
    expect($code)->toBe(255)
        ->and($stderr)->toBe("deploy.me: The HTTP proxy at 127.0.0.1:1 refused the connection.\n")
        ->and(file_get_contents("{$this->dir}/route.err"))->toBe('The HTTP proxy at 127.0.0.1:1 refused the connection.');
});
