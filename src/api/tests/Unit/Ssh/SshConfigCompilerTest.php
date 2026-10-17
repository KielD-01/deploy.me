<?php

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
