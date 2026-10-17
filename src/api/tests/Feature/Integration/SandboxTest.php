<?php

use App\Platform\Remote\RemoteFs;
use App\Platform\Remote\ServerProber;
use App\Platform\Ssh\Exceptions\HostKeyUnknown;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;

// Real ssh against the local sandbox (`make sandbox`), in a throwaway runtime
// folder so the app's own known_hosts is never touched. Skipped when the
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
});

function trustSandbox(): void
{
    app(KnownHosts::class)->trust(app(KnownHosts::class)->scan('sandbox'), null);
}

it('refuses an unknown host key, then connects once it is trusted', function () {
    $ssh = app(SshClient::class);

    expect(fn () => $ssh->run('sandbox', RemoteScript::inline('t', 'true'))->throw())->toThrow(HostKeyUnknown::class);

    $scan = app(KnownHosts::class)->scan('sandbox');
    expect($scan->keys[0]['fingerprint'])->toStartWith('SHA256:');

    app(KnownHosts::class)->trust($scan, null);

    expect(trim($ssh->run('sandbox', RemoteScript::inline('t', 'echo ok'))->throw()->stdout))->toBe('ok');
})->group('ssh-integration');

it('probes the sandbox', function () {
    trustSandbox();

    $probe = app(ServerProber::class)->probe('sandbox');

    expect($probe->os())->toContain('Ubuntu')
        ->and($probe->capabilities())->toMatchArray(['docker' => '28.3.2', 'gnu_mv' => true, 'flock' => true, 'sudo_nopasswd' => false])
        ->and(array_column($probe->data['stacks'], 'Name'))->toContain('infra')
        ->and($probe->diskFor('/opt/srv'))->not->toBeNull();
})->group('ssh-integration');

it('lists folders and creates one with an awkward name', function () {
    trustSandbox();
    $fs = app(RemoteFs::class);

    $opt = $fs->list('sandbox', '/opt');
    $names = collect($opt->entries)->keyBy('name');
    expect($names->keys()->all())->toContain('infra', 'srv')
        ->and($names['srv']->writable)->toBeTrue()
        ->and($names['infra']->writable)->toBeFalse();

    $folder = "/opt/srv/it dir 'quoted' $(date)";
    $fs->mkdir('sandbox', $folder);

    expect($fs->isDirectory('sandbox', $folder))->toBeTrue()
        ->and(collect($fs->list('sandbox', '/opt/srv')->entries)->pluck('name'))->toContain(basename($folder));

    app(SshClient::class)->run('sandbox', RemoteScript::inline('cleanup', 'rmdir -- "$DIR"', ['DIR' => $folder]))->throw();
})->group('ssh-integration');
