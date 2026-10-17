<?php

use App\Actions\Targets\ChangeTargetFolder;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Livewire\Projects\Targets;
use App\Models\AuditEntry;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// A target's folder: {folder}/{slug} by default, or an exact folder of its
// own; changed only while nothing deploy.me deployed runs from it.
beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    asClassic();
    Queue::fake();

    $this->server = Server::factory()->create(['name' => 'Build box', 'default_deploy_path' => '/opt/srv']);
    $this->project = Project::factory()->create(['name' => 'Demo', 'slug' => 'demo']);
});

it('assigns a project to an exact folder, whatever its name', function () {
    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('startAssigning')
        ->assertSet('folder', '/opt/srv/demo')
        ->call('pickFolderMode', 'exact')
        ->set('folder', '/srv/www/demo-stage/')
        ->assertSee('Deploys to /srv/www/demo-stage')
        ->set('deployAfter', false)
        ->set('prepare', false)
        ->call('assign')
        ->assertHasNoErrors()
        ->assertSee('It deploys to /srv/www/demo-stage.')
        ->assertSee('exact folder · server default is /opt/srv');

    $target = Target::query()->sole();

    expect($target->path)->toBe('/srv/www/demo-stage')
        ->and($target->base_dir)->toBe('/srv/www')
        ->and($target->folderNote())->toBe('exact folder');
});

it('refuses an exact folder it mustn’t deploy into', function (string $folder, string $message) {
    Target::factory()->create(['server_id' => $this->server->id, 'project_id' => Project::factory()->create(['name' => 'Shop', 'slug' => 'shop'])->id, 'base_dir' => '/srv/apps']);

    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('startAssigning')
        ->call('pickFolderMode', 'exact')
        ->set('folder', $folder)
        ->call('assign')
        ->assertHasErrors('folder')
        ->assertSee($message);

    expect(Target::query()->count())->toBe(1);
})->with([
    'one folder deep' => ['/demo', 'at least two folders deep'],
    'the shared infrastructure' => ['/opt/infra/demo', 'inside the shared infrastructure’s folder'],
    'a system folder' => ['/etc/demo', 'inside /etc, which belongs to the system'],
    'docker’s own' => ['/var/lib/docker/demo', 'inside /var/lib, which belongs to the system'],
    'a home folder' => ['/home/deploy', 'is a home folder'],
    'inside another target' => ['/srv/apps/shop/demo', 'inside /srv/apps/shop, where Shop deploys'],
    'around another target' => ['/srv/apps', 'Shop deploys inside it'],
    'the same folder' => ['/srv/apps/shop', 'Shop already deploys to /srv/apps/shop'],
]);

it('changes the folder of a target with nothing deployed yet, and leaves the server alone', function () {
    $target = Target::factory()->create(['server_id' => $this->server->id, 'project_id' => $this->project->id, 'base_dir' => '/opt/srv', 'bootstrapped_at' => now(), 'folder_scan' => ['items' => []]]);

    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('startMoving', $target->id)
        ->assertSee('Change the folder')
        ->assertSee('The folders Prepare made in /opt/srv/demo stay on the server')
        ->set('moveFolder', '/srv/apps/demo')
        ->call('changeFolder')
        ->assertHasNoErrors()
        ->assertSet('moving', null)
        ->assertSee('Build box now deploys to /srv/apps/demo. Nothing on the server changed; whatever was prepared in /opt/srv/demo stays there.');

    $target->refresh();

    expect($target->path)->toBe('/srv/apps/demo')
        ->and($target->bootstrapped_at)->toBeNull()
        ->and($target->folder_scan)->toBeNull()
        ->and(AuditEntry::query()->where('action', AuditAction::TargetFolderChanged->value)->sole()->subject)->toBe('Demo on Build box · /opt/srv/demo → /srv/apps/demo');
});

it('moves into another parent folder, named after the project', function () {
    $target = Target::factory()->create(['server_id' => $this->server->id, 'project_id' => $this->project->id, 'base_dir' => '/opt/srv']);

    app(ChangeTargetFolder::class)($target, '/srv/apps/');

    expect($target->refresh()->path)->toBe('/srv/apps/demo')->and($target->folderNote())->toBe('inside /srv/apps');
});

it('won’t move a live target, and says what runs there and how to move it', function () {
    $this->project->update(['versionable' => true, 'dockerized' => true, 'stack_name' => 'demo']);
    $target = Target::factory()->create(['server_id' => $this->server->id, 'project_id' => $this->project->id, 'base_dir' => '/opt/srv']);
    $release = Release::factory()->create(['target_id' => $target->id, 'name' => '20261016-2105']);
    $target->update(['current_release_id' => $release->id]);

    Livewire::test(Targets::class, ['projectId' => $this->project->id])
        ->call('startMoving', $target->id)
        ->assertSee('Demo is live in /opt/srv/demo, so deploy.me won’t move it.')
        ->assertSee('1 release in releases/ (current → 20261016-2105)', false)
        ->assertSee('the Compose stack')
        ->assertSee('Remove this target (nothing on the server changes)')
        ->assertDontSee('Use /')
        ->call('stopMoving')
        ->assertSet('moving', null);

    expect(fn () => app(ChangeTargetFolder::class)($target, '/srv/apps/demo', exact: true))
        ->toThrow(ValidationException::class, 'Demo is live in /opt/srv/demo, so deploy.me won’t move it');

    expect($target->refresh()->path)->toBe('/opt/srv/demo');
});

it('won’t move a target while a run is going on it', function () {
    $target = Target::factory()->create(['server_id' => $this->server->id, 'project_id' => $this->project->id, 'base_dir' => '/opt/srv']);
    Deployment::factory()->create(['target_id' => $target->id, 'status' => DeploymentStatus::Running]);

    expect(fn () => app(ChangeTargetFolder::class)($target, '/srv/apps/demo', exact: true))
        ->toThrow(ValidationException::class, 'A run is still going on this target.');
});

it('checks the new folder like a new one, minus the target’s own', function () {
    $target = Target::factory()->create(['server_id' => $this->server->id, 'project_id' => $this->project->id, 'base_dir' => '/opt/srv']);

    expect(fn () => app(ChangeTargetFolder::class)($target, '/opt/srv/demo', exact: true))->toThrow(ValidationException::class, 'It already deploys to /opt/srv/demo.')
        ->and(fn () => app(ChangeTargetFolder::class)($target, '/usr/local/demo', exact: true))->toThrow(ValidationException::class, 'which belongs to the system')
        ->and(app(ChangeTargetFolder::class)($target, '/opt/srv/demo-two', exact: true)->path)->toBe('/opt/srv/demo-two');
});

it('offers the folder change in the Console theme too', function () {
    auth()->user()->forceFill(['ui' => ['theme' => 'console']])->save();
    Target::factory()->create(['server_id' => $this->server->id, 'project_id' => $this->project->id, 'base_dir' => '/srv/www']);

    $this->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertSee('/srv/www/demo')
        ->assertSee('# inside /srv/www', false)
        ->assertSee('Change folder…');
});
