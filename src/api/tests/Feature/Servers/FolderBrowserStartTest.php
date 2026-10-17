<?php

use App\Livewire\Servers\Folders;
use App\Livewire\Servers\Show;
use App\Models\Server;
use App\Models\User;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use Livewire\Livewire;

// The folder browser can't pick its own starting folder (in the browser the
// page's value wins), so each page opens it at the nearest folder that exists.
beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->app->instance(SshClient::class, (new FakeSshClient)
        ->on('box', 'nearest-dir', FakeSshClient::ok('/opt/srv'))
        ->on('box', 'ls', lsOutput('/opt/srv', [['app', 'deploy', '755', true]], writable: true)));

    $this->server = Server::factory()->create(['ssh_alias' => 'box', 'default_deploy_path' => '/opt/srv']);
});

it('opens the server page’s file browser at the default deploy folder', function () {
    Livewire::test(Show::class, ['server' => $this->server])
        ->call('browse')
        ->assertSet('browsing', true)
        ->assertSet('browsePath', '/opt/srv');
});

it('opens the default-path chooser at the nearest folder that exists', function () {
    Livewire::test(Show::class, ['server' => $this->server])
        ->set('defaultPath', '/opt/srv/not-yet/there')
        ->call('choosePath')
        ->assertSet('choosingPath', true)
        ->assertSet('defaultPath', '/opt/srv');
});

it('opens the server folder picker of a mapping at the nearest folder that exists, and puts the typed path back on cancel', function () {
    Livewire::test(Folders::class, ['serverId' => $this->server->id])
        ->call('startAdding')
        ->set('remotePath', '/opt/srv/app/uploads')
        ->call('browseRemote')
        ->assertSet('browsingRemote', true)
        ->assertSet('remotePath', '/opt/srv')
        ->assertSee('Use this folder')
        ->call('closeBrowser')
        ->assertSet('browsingRemote', false)
        ->assertSet('remotePath', '/opt/srv/app/uploads');
});
