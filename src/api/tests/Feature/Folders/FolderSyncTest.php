<?php

use App\Actions\Folders\DispatchDueFolderSyncs;
use App\Actions\Folders\FolderSyncBusy;
use App\Actions\Folders\RunFolderSync;
use App\Actions\Folders\StartFolderSync;
use App\Enums\SyncDirection;
use App\Enums\SyncRunStatus;
use App\Events\FolderSyncUpdated;
use App\Jobs\SyncFolder;
use App\Models\FolderMapping;
use App\Models\Server;
use App\Models\SyncRun;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use App\Platform\Sync\RsyncCommand;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 0,
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-sync-'.bin2hex(random_bytes(4)),
    ]);
    Event::fake([FolderSyncUpdated::class]);

    $this->ssh = (new FakeSshClient)->on('box', 'mkdir', FakeSshClient::ok());
    $this->app->instance(SshClient::class, $this->ssh);
    $this->server = Server::factory()->create(['ssh_alias' => 'box']);
    $this->local = tempProject(['a.txt' => 'a']);
});

function mappingFor(Server $server, string $local, array $attributes = []): FolderMapping
{
    return FolderMapping::factory()->create(['server_id' => $server->id, 'local_path' => $local, 'remote_path' => '/opt/srv/app/uploads', ...$attributes]);
}

it('builds the rsync command for either direction', function () {
    $push = mappingFor($this->server, $this->local, ['delete_extraneous' => true, 'excludes' => ['*.tmp', '.DS_Store']]);
    $pull = mappingFor($this->server, $this->local, ['direction' => SyncDirection::Pull]);

    $command = RsyncCommand::for($push, '/run/cfg', dryRun: true, maxDelete: 3);

    expect($command)->toContain('--protect-args', '--itemize-changes', '--dry-run', '--delete', '--max-delete=3', '--exclude=*.tmp', '--exclude=.DS_Store', '--rsh=ssh -F /run/cfg')
        ->and(array_slice($command, -2))->toBe(["{$this->local}/", 'box:/opt/srv/app/uploads/'])
        ->and(array_slice(RsyncCommand::for($pull, '/run/cfg', dryRun: false, maxDelete: null), -2))->toBe(['box:/opt/srv/app/uploads/', "{$this->local}/"])
        ->and(RsyncCommand::for($pull, '/run/cfg', dryRun: false, maxDelete: null))->not->toContain('--delete', '--dry-run');
});

it('previews a push and records what it would change', function () {
    Process::fake(['*rsync*' => Process::result(implode("\n", [
        'cd+++++++++ 4096 avatars/',
        '<f+++++++++ 1200 avatars/1049.jpg',
        '<f.st...... 2100 exports/orders.csv',
        'Number of files: 5',
        'Number of regular files transferred: 2',
        'Total transferred file size: 3300 bytes',
    ]))]);
    $run = SyncRun::factory()->create(['folder_mapping_id' => mappingFor($this->server, $this->local)->id, 'dry_run' => true]);

    app(RunFolderSync::class)($run);
    $run->refresh();

    expect($run->status)->toBe(SyncRunStatus::Succeeded)
        ->and($run->change_count)->toBe(2)
        ->and($run->counts())->toMatchArray(['new' => 1, 'updated' => 1, 'folders' => 1])
        ->and($run->summary())->toBe('2 files · 3.2 KB')
        ->and($run->finished_at)->not->toBeNull();

    Process::assertRan(fn ($process) => in_array('--dry-run', $process->command, true) && end($process->command) === 'box:/opt/srv/app/uploads/');
    // A preview creates nothing, not even the destination folder.
    $this->ssh->assertNothingRan();
    Event::assertDispatched(FolderSyncUpdated::class);
});

it('creates the server folder before a real push', function () {
    Process::fake(['*rsync*' => Process::result('')]);
    $run = SyncRun::factory()->create(['folder_mapping_id' => mappingFor($this->server, $this->local)->id]);

    app(RunFolderSync::class)($run);

    expect($run->refresh()->status)->toBe(SyncRunStatus::Succeeded);
    $this->ssh->assertRan('box', 'mkdir');
    Process::assertRan(fn ($process) => ! in_array('--dry-run', $process->command, true));
});

it('creates the local folder a pull goes into', function () {
    Process::fake(['*rsync*' => Process::result('')]);
    $destination = sys_get_temp_dir().'/deployme-pull-'.bin2hex(random_bytes(3)).'/backups';
    $run = SyncRun::factory()->create(['folder_mapping_id' => mappingFor($this->server, $destination, ['direction' => SyncDirection::Pull])->id]);

    app(RunFolderSync::class)($run);

    expect($run->refresh()->status)->toBe(SyncRunStatus::Succeeded)
        ->and(is_dir($destination))->toBeTrue();
});

it('explains when rsync would delete more than the preview showed', function () {
    Process::fake(['*rsync*' => Process::result('', 'Deletions stopped due to --max-delete limit (1 skipped)', 25)]);
    $mapping = mappingFor($this->server, $this->local, ['delete_extraneous' => true]);
    $run = SyncRun::factory()->create(['folder_mapping_id' => $mapping->id, 'max_delete' => 2]);

    app(RunFolderSync::class)($run);

    expect($run->refresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($run->error)->toBe('It would have deleted more than 2 files, so deleting stopped there. Preview it to see why.');
});

it('refuses to push into the shared infrastructure, whatever was saved', function () {
    Process::fake();
    $run = SyncRun::factory()->create(['folder_mapping_id' => mappingFor($this->server, $this->local, ['remote_path' => '/opt/infra/caddy'])->id]);

    app(RunFolderSync::class)($run);

    expect($run->refresh()->error)->toContain('belongs to the shared infrastructure');
    Process::assertNothingRan();
});

it('refuses a folder outside the project folders', function () {
    Process::fake();
    $run = SyncRun::factory()->create(['folder_mapping_id' => mappingFor($this->server, '/etc/ssh')->id]);

    app(RunFolderSync::class)($run);

    expect($run->refresh()->error)->toContain('has to be inside');
    Process::assertNothingRan();
});

it('runs one sync at a time per mapping', function () {
    Queue::fake();
    $mapping = mappingFor($this->server, $this->local);

    app(StartFolderSync::class)($mapping, dryRun: true);

    expect(fn () => app(StartFolderSync::class)($mapping, dryRun: false))->toThrow(FolderSyncBusy::class);
    Queue::assertPushed(SyncFolder::class, 1);
});

it('starts scheduled syncs that are due, with a cap on deletions', function () {
    Queue::fake();
    $due = mappingFor($this->server, $this->local, ['schedule_interval' => 3600, 'next_run_at' => now()->subMinute(), 'delete_extraneous' => true]);
    mappingFor($this->server, $this->local, ['schedule_interval' => 3600, 'next_run_at' => now()->addMinutes(30)]);
    mappingFor($this->server, $this->local);

    expect(app(DispatchDueFolderSyncs::class)())->toBe(1);

    $run = SyncRun::query()->sole();
    expect($run->folder_mapping_id)->toBe($due->id)
        ->and($run->trigger)->toBe('schedule')
        ->and($run->dry_run)->toBeFalse()
        ->and($run->max_delete)->toBe(100)
        ->and($due->refresh()->next_run_at->isFuture())->toBeTrue();
});
