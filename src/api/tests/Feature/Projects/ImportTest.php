<?php

use App\Enums\CiState;
use App\Enums\ProjectSource;
use App\Jobs\PollCiStatus;
use App\Jobs\PublishRepository;
use App\Livewire\Projects\Import;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use App\Platform\Ssh\SshConfigReader;
use App\Support\JobStatus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
    $this->app->instance(SshConfigReader::class, new SshConfigReader([], sys_get_temp_dir()));
    Queue::fake();
});

it('imports a local folder with the settings it suggests', function () {
    $repo = tempGitRepo([
        'compose.prod.yaml' => fixtureText('compose/blue-green.yaml'),
        '.github/workflows/deploy.yml' => fixtureText('workflows/ghcr-sha.yml'),
        'deploy.sh' => "#!/bin/sh\n",
    ], remote: 'https://github.com/KielD-01/example.app.git');

    Livewire::test(Import::class)
        ->call('switchTo', 'local')
        ->set('path', $repo)
        ->call('inspect')
        ->assertSee('Git repository on main')
        ->assertSee('GitHub: KielD-01/example.app')
        ->assertSee('deploy.yml deploys over SSH too')
        ->assertSet('dockerized', true)
        ->assertSet('stackName', 'example')
        ->assertSet('composeFile', 'compose.prod.yaml')
        ->set('name', 'Example')
        ->set('slug', 'example.app')
        ->set('keepReleases', 3)
        ->call('import')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.show', 'example.app'));

    $project = Project::query()->sole();

    expect($project->source)->toBe(ProjectSource::Local)
        ->and($project->local_path)->toBe($repo)
        ->and($project->githubFullName())->toBe('KielD-01/example.app')
        ->and($project->git_remote)->toBe('origin')
        ->and($project->keep_releases)->toBe(3)
        ->and($project->stack_name)->toBe('example')
        ->and($project->compose_files)->toBe(['compose.prod.yaml'])
        ->and($project->app_services)->toBe(['app-blue', 'app-green', 'worker'])
        ->and($project->activation_script)->toBe('deploy.sh')
        ->and(array_column($project->delivery_strategies, 'key'))->toBe(['ghcr_image', 'push_archive', 'push_rsync', 'server_git']);

    Queue::assertPushed(PollCiStatus::class, fn (PollCiStatus $job) => $job->projectId === $project->id);
});

it('stays inside the project folders', function () {
    Livewire::test(Import::class)
        ->call('switchTo', 'local')
        ->set('path', '/etc')
        ->call('inspect')
        ->assertSee('Pick a folder inside')
        ->assertSet('detection', null);
});

it('won’t import the same folder twice', function () {
    $repo = tempGitRepo(['README.md' => "# Demo\n"]);
    Project::factory()->local($repo)->create();

    Livewire::test(Import::class)
        ->call('switchTo', 'local')
        ->set('path', $repo)
        ->call('inspect')
        ->assertSee('is already a project');
});

it('refuses names servers wouldn’t accept', function () {
    $repo = tempGitRepo(['compose.yaml' => "services:\n  app:\n    build: .\n"]);

    Livewire::test(Import::class)
        ->call('switchTo', 'local')
        ->set('path', $repo)
        ->call('inspect')
        ->set('slug', 'Not a slug!')
        ->set('stackName', 'bad.name')
        ->set('keepReleases', 11)
        ->call('import')
        ->assertHasErrors(['slug', 'stackName', 'keepReleases']);

    expect(Project::query()->count())->toBe(0);
});

it('puts a local repository on GitHub from the worker, then picks up the remote', function () {
    Credential::factory()->create(['owners' => ['KielD-01', 'MR-Studio-KZ-UA']]);
    $repo = tempGitRepo(['README.md' => "# Demo\n"]);

    $import = Livewire::test(Import::class)
        ->call('switchTo', 'local')
        ->set('path', $repo)
        ->call('inspect')
        ->assertSee('No GitHub remote')
        ->assertSee('Create and push')
        ->set('publishOwner', 'MR-Studio-KZ-UA')
        ->set('publishName', 'demo')
        ->call('publish')
        ->assertSee('Waiting for the worker');

    Queue::assertPushed(PublishRepository::class, fn (PublishRepository $job) => $job->path === $repo
        && $job->owner === 'MR-Studio-KZ-UA'
        && $job->name === 'demo'
        && $job->private
        && $job->connection === 'redis-deploys');

    // What the worker would have done.
    Process::path($repo)->run(['git', 'remote', 'add', 'origin', 'https://github.com/MR-Studio-KZ-UA/demo.git'])->throw();
    JobStatus::finish($import->get('publishId'), ['branch' => 'main']);

    $import->call('checkPublish')
        ->assertSet('publishId', null)
        ->assertSee('GitHub: MR-Studio-KZ-UA/demo')
        ->assertSee('Pushed main to GitHub.');
});

it('lists repositories from every token and imports one through the API', function () {
    Credential::factory()->create();
    fakeGitHubRepository();

    Livewire::test(Import::class)
        ->assertSee('demo')
        ->call('selectRepository', 'KielD-01/demo')
        ->assertSee('Branch main')
        ->assertSee('CI passing')
        ->assertSet('stackName', 'example')
        ->call('import')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.show', 'demo'));

    $project = Project::query()->sole();

    expect($project->source)->toBe(ProjectSource::Github)
        ->and($project->githubFullName())->toBe('KielD-01/demo')
        ->and($project->ci_state)->toBe(CiState::Passing)
        ->and($project->credential_id)->not->toBeNull()
        ->and($project->framework)->toBe('Laravel 13');

    Queue::assertNotPushed(PollCiStatus::class);
});

it('marks repositories that are already imported', function () {
    Credential::factory()->create();
    Project::factory()->create(['github_owner' => 'KielD-01', 'github_repo' => 'demo']);
    fakeGitHubRepository();

    Livewire::test(Import::class)
        ->assertSee('Imported')
        ->call('selectRepository', 'KielD-01/demo')
        ->assertSet('detection', null);
});

it('points to Settings when there’s no GitHub token', function () {
    Livewire::test(Import::class)->assertSee('Add a GitHub token');
});
