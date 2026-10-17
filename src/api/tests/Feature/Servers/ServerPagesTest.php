<?php

use App\Enums\DeploymentKind;
use App\Livewire\Servers\Show;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
});

function withReading(Server $server): Server
{
    $server->snapshots()->create([
        'captured_at' => now(),
        'cpu_usage' => 12.5,
        'mem_total' => 1000,
        'mem_available' => 400,
        'disks' => [['source' => '/dev/sda1', 'fstype' => 'ext4', 'size' => 1000, 'used' => 390, 'available' => 610, 'mount' => '/']],
        'stacks' => [['Name' => 'infra', 'Status' => 'running(3)', 'ConfigFiles' => '/opt/infra/compose.yaml']],
    ]);

    return $server;
}

it('lists servers with their latest readings', function () {
    withReading(Server::factory()->create(['name' => 'Build box']));

    $this->get('/servers')
        ->assertOk()
        ->assertSee('Build box')
        ->assertSee('13%')
        ->assertSee('60%')
        ->assertSee('39%');
});

it('invites you to add the first server', function () {
    $this->get('/servers')->assertOk()->assertSee('Add your first server');
});

it('shows a server with its stacks', function () {
    $server = withReading(Server::factory()->create(['name' => 'Build box']));

    $this->get("/servers/{$server->slug}")->assertOk()->assertSee('Build box')->assertSee('Probe now');

    Livewire::test(Show::class, ['server' => $server])
        ->set('tab', 'stacks')
        ->assertSee('infra')
        ->assertSee('Reserved');
});

it('saves server settings', function () {
    $server = Server::factory()->create();

    Livewire::test(Show::class, ['server' => $server])
        ->set('tab', 'settings')
        ->set('name', 'Renamed')
        ->set('envTag', 'staging')
        ->set('defaultPath', '/srv/apps/')
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->assertSee('Settings saved.');

    expect($server->refresh())
        ->name->toBe('Renamed')
        ->default_deploy_path->toBe('/srv/apps')
        ->env_tag->value->toBe('staging');
});

it('refuses a reserved default path', function () {
    Livewire::test(Show::class, ['server' => Server::factory()->create()])
        ->set('defaultPath', '/opt/infra')
        ->call('saveSettings')
        ->assertHasErrors('defaultPath');
});

it('removes a server from deploy.me', function () {
    $server = Server::factory()->create();

    Livewire::test(Show::class, ['server' => $server])->call('archive')->assertRedirect(route('servers.index'));

    expect($server->refresh()->archived_at)->not->toBeNull();
    $this->get("/servers/{$server->slug}")->assertNotFound();
});

it('adopts a stack the server already runs, from the Stacks tab', function () {
    $server = Server::factory()->create(['name' => 'Build box', 'default_deploy_path' => '/opt/srv']);
    $server->snapshots()->create([
        'captured_at' => now(),
        'stacks' => [
            ['Name' => 'infra', 'Status' => 'running(3)', 'ConfigFiles' => '/opt/infra/compose.yaml'],
            ['Name' => 'example', 'Status' => 'running(5)', 'ConfigFiles' => '/opt/srv/example/compose.prod.yml'],
        ],
    ]);
    $project = Project::factory()->create(['slug' => 'example', 'name' => 'example.dev', 'versionable' => false]);

    Livewire::test(Show::class, ['server' => $server])
        ->set('tab', 'stacks')
        ->assertSee('Adopt into example.dev')
        // The shared infrastructure is never on offer.
        ->assertDontSee('Adopt into infra')
        ->call('adoptStack', 'example')
        ->assertRedirect();

    $target = $project->targets()->sole();

    expect($target->path)->toBe('/opt/srv/example')
        ->and($target->server_id)->toBe($server->id)
        ->and(Deployment::query()->sole())
        ->kind->toBe(DeploymentKind::Adopt)
        ->target_id->toBe($target->id);
});

it('offers nothing to adopt for a stack no project would deploy into', function () {
    $server = Server::factory()->create(['default_deploy_path' => '/opt/srv']);
    $server->snapshots()->create([
        'captured_at' => now(),
        'stacks' => [['Name' => 'stranger', 'Status' => 'running(1)', 'ConfigFiles' => '/opt/srv/stranger/compose.yaml']],
    ]);
    // A project that deploys releases takes a folder over by converting it.
    Project::factory()->create(['slug' => 'stranger', 'versionable' => true]);

    Livewire::test(Show::class, ['server' => $server])
        ->set('tab', 'stacks')
        ->assertSee('Not managed by deploy.me')
        ->assertDontSee('Adopt')
        ->call('adoptStack', 'stranger')
        ->assertSee('can’t be adopted any more');
});
