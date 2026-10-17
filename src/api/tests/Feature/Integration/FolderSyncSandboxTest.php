<?php

use App\Actions\Folders\RunFolderSync;
use App\Enums\SyncDirection;
use App\Enums\SyncRunStatus;
use App\Events\FolderSyncUpdated;
use App\Models\FolderMapping;
use App\Models\Server;
use App\Models\SyncRun;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;
use Illuminate\Support\Facades\Event;

// Real rsync over ssh against the local sandbox (`make sandbox`), in a folder
// under /opt/srv that's removed afterwards. Skipped when it isn't running.
beforeEach(function () {
    if (gethostbyname('deployme-sshd-target') === 'deployme-sshd-target' || ! is_readable(storage_path('app/sandbox-ssh/ssh_config'))) {
        $this->markTestSkipped('The sandbox isn’t running: `make sandbox`.');
    }

    config([
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-it-'.bin2hex(random_bytes(4)),
        'deployme.ssh.user_known_hosts' => '/nonexistent',
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 50,
    ]);

    $this->app->singleton(SshConfigCompiler::class, fn () => SshConfigCompiler::fromConfig());
    $this->app->singleton(KnownHosts::class);
    $this->app->singleton(SshClient::class, ProcessSshClient::class);
    app(KnownHosts::class)->trust(app(KnownHosts::class)->scan('sandbox'), null);

    Event::fake([FolderSyncUpdated::class]);
    $this->remote = '/opt/srv/it-sync-'.bin2hex(random_bytes(3));
    $this->server = Server::factory()->create(['ssh_alias' => 'sandbox']);
});

afterEach(function () {
    if (isset($this->remote)) {
        app(SshClient::class)->run('sandbox', RemoteScript::inline('cleanup', 'rm -rf -- "$DIR"', ['DIR' => $this->remote]));
    }
});

function syncOnce(FolderMapping $mapping, bool $dryRun = false, ?int $maxDelete = null): SyncRun
{
    $run = SyncRun::factory()->create(['folder_mapping_id' => $mapping->id, 'dry_run' => $dryRun, 'max_delete' => $maxDelete]);
    app(RunFolderSync::class)($run);

    return $run->refresh();
}

function remoteListing(string $dir): string
{
    return app(SshClient::class)->run('sandbox', RemoteScript::inline(
        'ls',
        'if [ -d "$DIR" ]; then cd -- "$DIR" && find . -type f | sort | tr "\n" " "; else printf missing; fi',
        ['DIR' => $dir],
    ))->throw()->stdout;
}

it('previews, pushes, then deletes only what the preview showed', function () {
    $local = tempProject(['uploads/a.jpg' => 'aaa', 'uploads/sub/b.txt' => 'bbbb', 'uploads/skip.tmp' => 'x']).'/uploads';
    // Two folders deep, neither there yet.
    $remote = "{$this->remote}/uploads";
    $mapping = FolderMapping::factory()->create([
        'server_id' => $this->server->id, 'local_path' => $local, 'remote_path' => $remote, 'excludes' => ['*.tmp'],
    ]);

    $preview = syncOnce($mapping, dryRun: true);
    expect($preview->status)->toBe(SyncRunStatus::Succeeded, (string) $preview->error)
        ->and($preview->counts()['new'])->toBe(2)
        ->and(remoteListing($this->remote))->toBe('missing');

    $push = syncOnce($mapping);
    expect($push->status)->toBe(SyncRunStatus::Succeeded, (string) $push->error)
        ->and($push->stats['transferred'])->toBe(2)
        ->and(remoteListing($remote))->toBe('./a.jpg ./sub/b.txt ');

    // A file only the server has: deleting it needs deleting on, and a preview that shows it.
    app(SshClient::class)->run('sandbox', RemoteScript::inline('extra', 'printf x > "$DIR/stray.log"', ['DIR' => $remote]))->throw();
    $mapping->update(['delete_extraneous' => true]);

    $preview = syncOnce($mapping, dryRun: true);
    expect($preview->counts()['deleted'])->toBe(1)
        ->and(collect($preview->changes)->firstWhere('kind', 'deleted')['path'])->toBe('stray.log')
        ->and(remoteListing($remote))->toContain('stray.log');

    $apply = syncOnce($mapping, maxDelete: (int) $preview->stats['deleted']);
    expect($apply->status)->toBe(SyncRunStatus::Succeeded, (string) $apply->error)
        ->and(remoteListing($remote))->toBe('./a.jpg ./sub/b.txt ');
})->group('ssh-integration');

it('stops deleting at the cap', function () {
    $local = tempProject(['uploads/a.jpg' => 'a']).'/uploads';
    app(SshClient::class)->run('sandbox', RemoteScript::inline('fill', 'mkdir -p -- "$DIR" && for n in 1 2 3; do printf x > "$DIR/old-$n.log"; done', ['DIR' => $this->remote]))->throw();
    $mapping = FolderMapping::factory()->create([
        'server_id' => $this->server->id, 'local_path' => $local, 'remote_path' => $this->remote, 'delete_extraneous' => true,
    ]);

    $run = syncOnce($mapping, maxDelete: 1);

    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->error)->toContain('deleting stopped there');
})->group('ssh-integration');

it('pulls a server folder into a new folder on the Mac', function () {
    app(SshClient::class)->run('sandbox', RemoteScript::inline('fill', 'mkdir -p -- "$DIR/nested" && printf dump > "$DIR/db.sql" && printf n > "$DIR/nested/n.txt"', ['DIR' => $this->remote]))->throw();
    $destination = sys_get_temp_dir().'/deployme-pull-'.bin2hex(random_bytes(3)).'/backups';
    $mapping = FolderMapping::factory()->create([
        'server_id' => $this->server->id, 'direction' => SyncDirection::Pull, 'local_path' => $destination, 'remote_path' => $this->remote,
    ]);

    $preview = syncOnce($mapping, dryRun: true);
    expect($preview->status)->toBe(SyncRunStatus::Succeeded, (string) $preview->error)
        ->and($preview->counts()['new'])->toBe(2)
        ->and(file_exists(dirname($destination)))->toBeFalse();

    $run = syncOnce($mapping);

    expect($run->status)->toBe(SyncRunStatus::Succeeded, (string) $run->error)
        ->and(file_get_contents("{$destination}/db.sql"))->toBe('dump')
        ->and(is_file("{$destination}/nested/n.txt"))->toBeTrue();
})->group('ssh-integration');
