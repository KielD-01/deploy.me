<?php

use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;

// deploy-link-shared.sh against the local sandbox (`make sandbox`). Skipped when
// the sandbox isn't running.
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

    $this->dir = '/opt/srv/it-shared-'.bin2hex(random_bytes(3));
    $this->shell = fn (string $script, array $vars = []) => app(SshClient::class)->run('sandbox', RemoteScript::inline('shell', $script, $vars))->throw()->stdout;
});

afterEach(function () {
    if (isset($this->dir)) {
        app(SshClient::class)->run('sandbox', RemoteScript::inline('shell', 'rm -rf -- "$DIR"', ['DIR' => $this->dir]));
    }
});

it('seeds shared/ from the release, starts a missing folder empty, and never makes a missing file a folder', function () {
    $release = "{$this->dir}/releases/20260911-120000";
    ($this->shell)(<<<'SH'
        mkdir -p "$RELEASE/storage/logs" "$SHARED"
        printf 'kept' > "$RELEASE/storage/logs/app.log"
        SH, ['RELEASE' => $release, 'SHARED' => "{$this->dir}/shared"]);

    $result = app(SshClient::class)->run('sandbox', RemoteScript::file('deploy-link-shared', [
        'RELEASE' => $release, 'SHARED' => "{$this->dir}/shared",
        'DIRS' => "storage\nuploads\n.env\nconfig/settings.local.php", 'FILES' => '',
    ]));

    $state = ($this->shell)(<<<'SH'
        cd "$RELEASE"
        for p in storage uploads .env config/settings.local.php; do
          if [ -L "$p" ]; then link=linked; else link=unlinked; fi
          if [ -d "$SHARED/$p" ]; then kind=folder; elif [ -e "$SHARED/$p" ]; then kind=file; else kind=absent; fi
          echo "$p $link $kind"
        done
        cat "$SHARED/storage/logs/app.log"
        SH, ['RELEASE' => $release, 'SHARED' => "{$this->dir}/shared"]);

    expect($result->exitCode)->toBe(0, $result->stdout)
        ->and($result->stdout)->toContain("✓ shared/storage starts from this release's copy")
        ->and($result->stdout)->toContain("! Not linked: .env isn't in shared/ yet, and this release has no copy of it")
        ->and($state)->toBe("storage linked folder\nuploads linked folder\n.env unlinked absent\nconfig/settings.local.php unlinked absent\nkept");
})->group('ssh-integration');
