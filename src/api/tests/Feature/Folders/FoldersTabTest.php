<?php

use App\Enums\SyncRunStatus;
use App\Jobs\SyncFolder;
use App\Livewire\Servers\Folders;
use App\Models\FolderMapping;
use App\Models\Server;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
    Queue::fake();

    $this->server = Server::factory()->create(['name' => 'Build box', 'default_deploy_path' => '/opt/srv']);
    $this->local = tempProject(['uploads/a.jpg' => 'a']).'/uploads';
});

it('adds a mapping and previews it straight away', function () {
    Livewire::test(Folders::class, ['serverId' => $this->server->id])
        ->call('startAdding')
        ->assertSet('remotePath', '/opt/srv')
        ->set('localPath', $this->local)
        ->assertSet('name', 'Uploads')
        ->set('remotePath', '/opt/srv/app/uploads')
        ->set('excludes', "*.tmp\n\n.DS_Store\n")
        ->set('schedule', '3600')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Saved Uploads.')
        ->assertSee('Comparing the two folders');

    $mapping = FolderMapping::query()->sole();

    expect($mapping->local_path)->toBe($this->local)
        ->and($mapping->excludes)->toBe(['*.tmp', '.DS_Store'])
        ->and($mapping->schedule_interval)->toBe(3600)
        ->and($mapping->next_run_at->isFuture())->toBeTrue()
        ->and(SyncRun::query()->sole()->dry_run)->toBeTrue();

    Queue::assertPushed(SyncFolder::class);
});

it('refuses to push into the shared infrastructure', function () {
    Livewire::test(Folders::class, ['serverId' => $this->server->id])
        ->call('startAdding')
        ->set('name', 'Caddy')
        ->set('localPath', $this->local)
        ->set('remotePath', '/opt/infra/caddy')
        ->call('save')
        ->assertHasErrors('remotePath')
        ->assertSee('belongs to the shared infrastructure');

    expect(FolderMapping::query()->count())->toBe(0);
});

it('asks for a passkey before deleting at the destination, and keeps the form meanwhile', function () {
    Livewire::test(Folders::class, ['serverId' => $this->server->id])
        ->call('startAdding')
        ->set('localPath', $this->local)
        ->set('remotePath', '/opt/srv/app/uploads')
        ->set('deleteExtraneous', true)
        ->assertRedirect(route('password.confirm'));

    session(['auth.password_confirmed_at' => time()]);

    Livewire::test(Folders::class, ['serverId' => $this->server->id])
        ->assertSet('formOpen', true)
        ->assertSet('deleteExtraneous', true)
        ->assertSet('localPath', $this->local)
        ->assertSet('name', 'Uploads');
});

it('shows a preview and applies it, deleting no more than it showed', function () {
    $mapping = FolderMapping::factory()->create(['server_id' => $this->server->id, 'local_path' => $this->local, 'delete_extraneous' => true]);
    $preview = SyncRun::factory()->create([
        'folder_mapping_id' => $mapping->id,
        'dry_run' => true,
        'status' => SyncRunStatus::Succeeded,
        'change_count' => 3,
        'stats' => ['transferred' => 2, 'transferred_bytes' => 3300, 'deleted' => 1, 'counts' => ['new' => 1, 'updated' => 1, 'deleted' => 1, 'folders' => 0, 'links' => 0]],
        'changes' => [
            ['kind' => 'new', 'path' => 'avatars/1049.jpg', 'size' => 1200],
            ['kind' => 'updated', 'path' => 'exports/orders.csv', 'size' => 2100],
            ['kind' => 'deleted', 'path' => 'tmp/old.zip', 'size' => 0],
        ],
    ]);

    $page = Livewire::test(Folders::class, ['serverId' => $this->server->id])
        ->call('showRun', $preview->id)
        ->assertSee('Dry run · Uploads')
        ->assertSee('3 changes:')
        ->assertSee('1 new, 1 updated, 1 deleted')
        ->assertSee(['avatars/1049.jpg', '− delete', 'tmp/old.zip'])
        ->call('apply')
        ->assertSee('Applying 3 changes to Uploads…');

    $real = SyncRun::query()->where('dry_run', false)->sole();
    expect($real->max_delete)->toBe(1);
    Queue::assertPushed(SyncFolder::class, fn (SyncFolder $job) => $job->runId === $real->id);

    // Once it's done, the row says how it went and the notice goes.
    $real->update(['status' => SyncRunStatus::Succeeded, 'finished_at' => now(), 'stats' => ['transferred' => 2, 'transferred_bytes' => 3300, 'deleted' => 1]]);
    $page->call('refreshRuns')
        ->assertDontSee('Applying 3 changes')
        ->assertSee('Last sync');
});

it('starts with a preview when deleting is on, and syncs straight away when it isn’t', function () {
    $deleting = FolderMapping::factory()->create(['server_id' => $this->server->id, 'local_path' => $this->local, 'delete_extraneous' => true]);
    $plain = FolderMapping::factory()->create(['server_id' => $this->server->id, 'local_path' => $this->local, 'name' => 'Logs']);

    Livewire::test(Folders::class, ['serverId' => $this->server->id])
        ->call('syncNow', $deleting->id)
        ->assertSee('so this starts with a preview')
        ->call('syncNow', $plain->id)
        ->assertSee('Syncing Logs…');

    expect($deleting->runs()->sole()->dry_run)->toBeTrue()
        ->and($plain->runs()->sole()->dry_run)->toBeFalse();
});

it('removes a mapping and leaves the files alone', function () {
    $mapping = FolderMapping::factory()->create(['server_id' => $this->server->id, 'local_path' => $this->local]);

    Livewire::test(Folders::class, ['serverId' => $this->server->id])
        ->call('remove', $mapping->id)
        ->assertSee('Removed Uploads. Files on both sides stay where they are.');

    expect(FolderMapping::query()->count())->toBe(0)
        ->and(is_file($this->local.'/a.jpg'))->toBeTrue();
});

it('opens the Mac folder picker at the nearest folder that exists, and puts the typed path back on cancel', function () {
    $typed = dirname($this->local).'/new/backups';

    Livewire::test(Folders::class, ['serverId' => $this->server->id])
        ->call('startAdding')
        ->set('localPath', $typed)
        ->call('browseLocal')
        ->assertSet('browsingLocal', true)
        ->assertSet('localPath', realpath(dirname($this->local)))
        ->call('closeBrowser')
        ->assertSet('browsingLocal', false)
        ->assertSet('localPath', $typed);
});

it('offers to review a recent preview', function () {
    $mapping = FolderMapping::factory()->create(['server_id' => $this->server->id, 'local_path' => $this->local]);
    $preview = SyncRun::factory()->create([
        'folder_mapping_id' => $mapping->id, 'dry_run' => true, 'status' => SyncRunStatus::Succeeded, 'change_count' => 1, 'finished_at' => now(),
    ]);

    Livewire::test(Folders::class, ['serverId' => $this->server->id])
        ->assertSee('Review the last preview')
        ->call('showRun', $preview->id)
        ->assertSee('Dry run · Uploads')
        ->assertDontSee('Review the last preview');
});

it('has a Folders tab on the server page', function () {
    FolderMapping::factory()->create(['server_id' => $this->server->id, 'local_path' => $this->local]);

    $this->get(route('servers.show', ['server' => $this->server, 'tab' => 'folders']))
        ->assertOk()
        ->assertSee('Folder mappings')
        ->assertSee('Push · by hand')
        ->assertSee('Never synced');
});
