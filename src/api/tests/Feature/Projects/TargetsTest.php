<?php

use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Livewire\Projects\Targets;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Server;
use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    Queue::fake();

    $this->server = Server::factory()->create(['name' => 'Build box', 'default_deploy_path' => '/opt/srv']);
    $this->project = Project::factory()->create(['name' => 'Demo', 'slug' => 'demo']);
});

it('assigns a project to a server and prepares it', function () {
    $this->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("A=1\n")]);

    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('startAssigning')
        ->assertSet('serverId', $this->server->id)
        ->assertSet('baseDir', '/opt/srv')
        ->assertSee('Deploys to /opt/srv/demo')
        ->assertSee('shared/')
        ->assertSee('Deploy main right after assigning')
        ->set('deployAfter', false)
        ->assertSee('Create the folders and upload the 1 env file now')
        ->call('assign')
        ->assertHasNoErrors()
        ->assertRedirect(route('deployments.show', Deployment::query()->sole()));

    $target = Target::query()->sole();

    expect($target->path)->toBe('/opt/srv/demo')
        ->and($target->server_id)->toBe($this->server->id)
        ->and(Deployment::query()->sole()->kind)->toBe(DeploymentKind::Bootstrap);

    Queue::assertPushed(RunDeployment::class);
});

it('assigns into a custom folder without preparing it yet', function () {
    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('startAssigning')
        ->set('baseDir', '/srv/apps/')
        ->set('deployAfter', false)
        ->set('prepare', false)
        ->call('assign')
        ->assertSee('Assigned to Build box. It deploys to /srv/apps/demo.')
        ->assertSee('inside /srv/apps · server default is /opt/srv');

    expect(Target::query()->sole()->base_dir)->toBe('/srv/apps');
    Queue::assertNothingPushed();
});

it('refuses folders it mustn’t deploy into', function (string $folder, string $message) {
    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('startAssigning')
        ->set('baseDir', $folder)
        ->call('assign')
        ->assertHasErrors('baseDir')
        ->assertSee($message);

    expect(Target::query()->count())->toBe(0);
})->with([
    'the shared infrastructure' => ['/opt/infra', 'inside the shared infrastructure’s folder'],
    'a relative path' => ['srv/apps', 'Use an absolute folder path'],
    'a way out' => ['/opt/srv/../etc', 'Use an absolute folder path'],
]);

it('won’t put two projects in the same folder, or one project twice on a server', function () {
    Target::factory()->create(['project_id' => $this->project->id, 'server_id' => $this->server->id]);
    $other = Project::factory()->create(['slug' => 'other']);
    Target::factory()->create(['project_id' => $other->id, 'server_id' => Server::factory()->create()->id]);

    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('startAssigning')
        ->assertSee('already runs this project')
        ->call('pickServer', $this->server->id)
        ->call('assign')
        ->assertHasErrors('baseDir')
        ->assertSee('Demo is already on Build box.');
});

it('removes a target and keeps its runs', function () {
    $target = Target::factory()->create(['project_id' => $this->project->id, 'server_id' => $this->server->id]);
    $run = Deployment::factory()->create(['target_id' => $target->id, 'status' => DeploymentStatus::Succeeded]);

    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('remove', $target->id)
        ->assertSee('Removed Build box. Nothing on the server changed.');

    expect($target->refresh()->archived_at)->not->toBeNull()
        ->and($run->fresh())->not->toBeNull();
});

it('won’t remove a target while a run is going', function () {
    $target = Target::factory()->create(['project_id' => $this->project->id, 'server_id' => $this->server->id]);
    Deployment::factory()->create(['target_id' => $target->id, 'status' => DeploymentStatus::Running]);

    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('remove', $target->id)
        ->assertSee('A run is still going on this target.');

    expect($target->refresh()->archived_at)->toBeNull();
});

it('breaks a folder’s deploy lock from the target’s menu, once you confirm with a passkey', function () {
    $target = Target::factory()->create(['project_id' => $this->project->id, 'server_id' => $this->server->id]);
    $confirm = "Break the deploy lock on Build box in {$target->path}? Only do this when you know no deploy is running there.";

    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->assertSee(['Break lock…', $confirm])
        ->call('breakLock', $target->id)
        ->assertRedirect(route('password.confirm'));
    expect(Deployment::query()->count())->toBe(0);

    session(['auth.password_confirmed_at' => time()]);
    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('breakLock', $target->id)
        ->assertRedirect(route('deployments.show', Deployment::query()->sole()));

    expect(Deployment::query()->sole()->kind)->toBe(DeploymentKind::Unlock);
    Queue::assertPushed(RunDeployment::class);

    // On record with every other run.
    $this->get('/deployments')->assertOk()->assertSee('Break lock');
});

it('won’t break a lock while a run is going on the target', function () {
    $target = Target::factory()->create(['project_id' => $this->project->id, 'server_id' => $this->server->id]);
    Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Running]);
    session(['auth.password_confirmed_at' => time()]);

    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->assertDontSee('Break lock…')
        ->call('breakLock', $target->id)
        ->assertSee('A run is still going on this target.');

    expect(Deployment::query()->count())->toBe(1);
});

it('shows targets on the server page, the dashboard and the projects list', function () {
    $target = Target::factory()->create(['project_id' => $this->project->id, 'server_id' => $this->server->id]);
    Deployment::factory()->create(['target_id' => $target->id, 'status' => DeploymentStatus::Succeeded]);

    $this->get(route('servers.show', $this->server))->assertOk()->assertSee('/opt/srv/demo')->assertSee('Prepare #');
    asClassic();
    $this->get('/')->assertOk()->assertSee('1 project');
    $this->get('/projects')->assertOk()->assertSee('Build box')->assertSee('Prepare #');
});
