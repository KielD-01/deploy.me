<?php

use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;
use App\Platform\Ssh\SshResult;

// deploy-lease.sh and deploy-unlock.sh against the local sandbox (`make
// sandbox`): a finished run's lock is taken over only while it still names
// that run, and a lock broken by hand says whose it was. Skipped when the
// sandbox isn't running.
beforeEach(function () {
    if (gethostbyname('deployme-sshd-target') === 'deployme-sshd-target' || ! is_readable(storage_path('app/sandbox-ssh/ssh_config'))) {
        $this->markTestSkipped('The sandbox isn’t running: `make sandbox`.');
    }

    config([
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-it-'.bin2hex(random_bytes(4)),
        'deployme.ssh.user_known_hosts' => '/nonexistent',
    ]);

    $this->app->singleton(SshConfigCompiler::class, fn () => SshConfigCompiler::fromConfig());
    $this->app->singleton(KnownHosts::class);
    $this->app->singleton(SshClient::class, ProcessSshClient::class);
    app(KnownHosts::class)->trust(app(KnownHosts::class)->scan('sandbox'), null);

    $this->dir = '/opt/srv/it-lock-'.bin2hex(random_bytes(3));
});

afterEach(function () {
    if (isset($this->dir)) {
        app(SshClient::class)->run('sandbox', RemoteScript::inline('shell', 'rm -rf -- "$DIR"', ['DIR' => $this->dir]));
    }
});

/**
 * @param  array<string, string|int>  $vars
 */
function onSandboxLock(string $dir, string $script, array $vars): SshResult
{
    return app(SshClient::class)->run('sandbox', RemoteScript::file($script, ['TARGET' => $dir, ...$vars]));
}

it('takes over a finished run’s lock only while the lock still names that run', function () {
    $lease = fn (int $owner, string $takeover = '') => onSandboxLock($this->dir, 'deploy-lease', ['OWNER' => $owner, 'STALE_AFTER' => 7200, 'TAKEOVER' => $takeover]);

    expect($lease(7)->stdout)->toContain('::deployme::lease=acquired')
        ->and($lease(8)->exitCode)->toBe(20)
        // Run 6 is over, but the lock is run 7's: it stays put.
        ->and($lease(8, '6')->stdout)->toContain('::deployme::holder=7');

    $taken = $lease(8, '7');

    expect($taken->exitCode)->toBe(0)
        ->and($taken->stdout)->toContain('::deployme::lease=taken_over')->toContain('::deployme::previous=7')
        ->and(onSandboxLock($this->dir, 'deploy-unlock', ['OWNER' => 7])->stdout)->toContain('belongs to deploy #8; left it alone');
})->group('ssh-integration');

it('breaks a lock by hand whoever holds it, and says whose it was', function () {
    onSandboxLock($this->dir, 'deploy-lease', ['OWNER' => 41, 'STALE_AFTER' => 7200, 'TAKEOVER' => ''])->throw();

    $broken = onSandboxLock($this->dir, 'deploy-unlock', ['OWNER' => 99, 'FORCE' => 1]);
    $again = onSandboxLock($this->dir, 'deploy-unlock', ['OWNER' => 99, 'FORCE' => 1]);

    expect($broken->exitCode)->toBe(0)
        ->and($broken->stdout)->toContain("✓ Broke the lock deploy #41 held on {$this->dir}")->toContain('::deployme::broken=1')->toContain('::deployme::previous=41')
        ->and($again->stdout)->toContain('::deployme::broken=0')
        ->and(onSandboxLock($this->dir, 'deploy-lease', ['OWNER' => 42, 'STALE_AFTER' => 7200, 'TAKEOVER' => ''])->stdout)->toContain('::deployme::lease=acquired');
})->group('ssh-integration');
