<?php

use App\Enums\RouteKind;
use App\Platform\Ssh\Route\Route;
use App\Platform\Ssh\SshConfigCompiler;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/deployme-compile-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0700, true);
    file_put_contents($this->dir.'/config', "Host a\n  HostName 203.0.113.10\n  UseKeychain yes\n");
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->dir)));

function compiler(string $dir, ?string $agent = '/run/host-services/ssh-auth.sock'): SshConfigCompiler
{
    return new SshConfigCompiler("{$dir}/config", ["{$dir}/missing.conf"], "{$dir}/run", "{$dir}/known_hosts", $agent, 'roman', 10);
}

it('puts the enforced options before the user config', function () {
    $config = compiler($this->dir)->render();

    expect(strpos($config, 'StrictHostKeyChecking yes'))->toBeLessThan(strpos($config, "Include {$this->dir}/config"))
        ->and($config)->toContain('IgnoreUnknown UseKeychain')
        ->toContain('BatchMode yes')
        ->toContain('IdentityAgent /run/host-services/ssh-auth.sock')
        ->toContain("ControlPath {$this->dir}/run/cm/%C")
        ->toContain('User roman')
        ->not->toContain('missing.conf');
});

it('keeps a connection open for longer than the health-check interval, so a check reuses it', function () {
    preg_match('/ControlPersist (\d+)s/', compiler($this->dir)->render(), $match);

    // Settings -> Schedules' default health_interval is 60 s.
    expect((int) $match[1])->toBeGreaterThan(60)->toBe(150);
});

it('switches the agent off when there is no socket', function () {
    expect(compiler($this->dir, null)->render())->toContain('IdentityAgent none');
});

it('writes a private config, known_hosts and socket folder', function () {
    $path = compiler($this->dir)->compile();

    expect(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600')
        ->and(is_file("{$this->dir}/run/known_hosts"))->toBeTrue()
        ->and(is_dir("{$this->dir}/run/cm"))->toBeTrue()
        ->and(compiler($this->dir)->compile())->toBe($path);
});

it('is valid for the ssh on this machine', function () {
    $path = compiler($this->dir)->compile();

    exec('ssh -F '.escapeshellarg($path).' -G a 2>&1', $output, $code);

    expect($code)->toBe(0)->and(implode("\n", $output))->toContain('hostname 203.0.113.10');
})->skip(fn () => trim((string) shell_exec('command -v ssh')) === '', 'ssh is not installed');

/**
 * @param  list<array<string, mixed>>  $hosts  servers entered by hand
 * @param  list<array<string, mixed>>  $routes
 */
function manualCompiler(string $dir, array $hosts, array $routes = []): SshConfigCompiler
{
    return new SshConfigCompiler("{$dir}/config", [], "{$dir}/run", "{$dir}/known_hosts", null, 'roman', 10,
        routes: fn () => $routes, manualHosts: fn () => $hosts);
}

function manualHost(array $overrides = []): array
{
    return ['alias' => 'kz-prod', 'host' => '203.0.113.10', 'user' => 'deploy', 'port' => 2222, 'key' => '/root/.ssh/kz_deploy', 'jump' => null, ...$overrides];
}

it('writes a Host block for each server entered by hand, before the user’s config', function () {
    $config = manualCompiler($this->dir, [
        manualHost(['jump' => 'a']),
        manualHost(['alias' => 'agent-only', 'host' => '2001:db8::7', 'user' => 'root', 'port' => 22, 'key' => null]),
    ])->render();

    expect($config)->toContain("Host kz-prod\n  HostName 203.0.113.10\n  User deploy\n  Port 2222\n  IdentityFile /root/.ssh/kz_deploy\n  IdentitiesOnly yes\n  ProxyJump a\n")
        ->toContain("Host agent-only\n  HostName 2001:db8::7\n  User root\n  Port 22\n\n")
        ->and(strpos($config, 'BatchMode yes'))->toBeLessThan(strpos($config, 'Host agent-only'))
        ->and(strpos($config, 'Host kz-prod'))->toBeLessThan(strpos($config, "Host *\nInclude {$this->dir}/config"));
});

it('quotes a key whose name has a space', function () {
    expect(manualCompiler($this->dir, [manualHost(['key' => '/root/.ssh/kz deploy'])])->render())
        ->toContain('  IdentityFile "/root/.ssh/kz deploy"');
});

it('leaves out a server entered by hand whose values don’t belong in an ssh config', function (array $bad) {
    $config = manualCompiler($this->dir, [manualHost($bad)])->render();

    expect($config)->not->toContain('kz-prod')->not->toContain('evil')
        ->and(substr_count($config, 'Host *'))->toBe(2);
})->with([
    'an alias that reads as an option' => [['alias' => '-oProxyCommand=evil']],
    'an alias with a pattern' => [['alias' => 'kz-*']],
    'a host with a space' => [['host' => '203.0.113.10 evil']],
    'a bracketed host' => [['host' => '[2001:db8::7]']],
    'a user with a newline' => [['user' => "deploy\n  ProxyCommand evil"]],
    'port 0' => [['port' => 0]],
    'a relative key' => [['key' => 'kz_deploy']],
    'a key with a %-token' => [['key' => '/root/.ssh/%h']],
    'a jump host that reads as an option' => [['jump' => '-J evil']],
]);

it('lets a route set on the server page win over the jump host typed in with it', function () {
    $path = manualCompiler($this->dir, [manualHost(['jump' => 'a'])], [
        ['id' => 3, 'alias' => 'kz-prod', 'route' => new Route(RouteKind::Jump, jump: 'bastion')],
    ])->compile();

    exec('ssh -F '.escapeshellarg($path).' -G kz-prod 2>&1', $output, $code);

    expect($code)->toBe(0)->and(implode("\n", $output))->toContain('proxyjump bastion')->not->toContain('proxyjump a');
})->skip(fn () => trim((string) shell_exec('command -v ssh')) === '', 'ssh is not installed');

it('resolves a server entered by hand with the ssh on this machine', function () {
    $path = manualCompiler($this->dir, [manualHost(['jump' => 'a'])])->compile();

    exec('ssh -F '.escapeshellarg($path).' -G kz-prod 2>&1', $output, $code);
    $resolved = implode("\n", $output);

    expect($code)->toBe(0)
        ->and($resolved)->toContain('hostname 203.0.113.10')
        ->toContain('user deploy')
        ->toContain('port 2222')
        ->toContain('identityfile /root/.ssh/kz_deploy')
        ->toContain('identitiesonly yes')
        ->toContain('proxyjump a')
        ->toContain('stricthostkeychecking true')
        ->toContain('batchmode yes');
})->skip(fn () => trim((string) shell_exec('command -v ssh')) === '', 'ssh is not installed');
