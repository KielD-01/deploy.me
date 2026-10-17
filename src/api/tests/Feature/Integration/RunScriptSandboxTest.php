<?php

use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;

// deploy-run-script.sh against the local sandbox (`make sandbox`): a project's
// own deploy script gets the release's image pins and the deploy's registry
// login in its environment. Skipped when the sandbox isn't running.
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

    $this->dir = '/opt/srv/it-script-'.bin2hex(random_bytes(3));
});

afterEach(function () {
    if (isset($this->dir)) {
        app(SshClient::class)->run('sandbox', RemoteScript::inline('shell', 'rm -rf -- "$DIR"', ['DIR' => $this->dir]));
    }
});

it('gives a project’s own deploy script the release’s image pins and the deploy’s registry login', function () {
    $release = "{$this->dir}/releases/20260911-120000";
    app(SshClient::class)->run('sandbox', RemoteScript::inline('shell', <<<'SH'
        mkdir -p "$RELEASE" && cd "$RELEASE"
        printf 'IMAGE_TAG=f601286\nbad-name=nope\n' > .deployme-pins.env
        printf '%s\n' 'echo "tag=$IMAGE_TAG config=${DOCKER_CONFIG:-none}"' > deploy.sh
        SH, ['RELEASE' => $release]))->throw();

    $result = app(SshClient::class)->run('sandbox', RemoteScript::file('deploy-run-script', [
        'DIR' => $release, 'SCRIPT' => 'deploy.sh', 'RELEASE' => '20260911-120000', 'COMMIT' => 'f601286',
        'TARGET' => $this->dir, 'DOCKER_CONFIG_DIR' => "{$this->dir}/.deploy/docker-7",
    ]));

    expect($result->exitCode)->toBe(0, $result->stdout)
        ->and($result->stdout)->toContain("tag=f601286 config={$this->dir}/.deploy/docker-7")
        ->and($result->stdout)->not->toContain('bad-name');
})->group('ssh-integration');
