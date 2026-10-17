<?php

use App\Deploy\Delivery\ImagePlan;
use App\Enums\CiState;
use App\Livewire\Projects\DeployDialog;
use App\Livewire\Projects\Environment;
use App\Livewire\Projects\Hooks;
use App\Livewire\Projects\Show;
use App\Models\Credential;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Release;
use App\Models\User;
use App\Platform\Detection\WorkflowAnalyzer;
use App\Support\HookPresets;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
});

it('lists projects with their source, deploy mode and CI', function () {
    Project::factory()->dockerized('example')->create(['name' => 'example.dev', 'github_repo' => 'example.dev', 'ci_state' => CiState::Passing]);
    Project::factory()->local('/srv/code/arcade')->create(['name' => 'arcade', 'versionable' => false, 'ci_state' => CiState::NoRemote]);

    $this->get('/projects')
        ->assertOk()
        ->assertSee('2 projects · 1 on GitHub, 1 local folder')
        ->assertSee('KielD-01/example.dev')
        ->assertSee('Docker · example')
        ->assertSee('/srv/code/arcade')
        ->assertSee('In place')
        ->assertSee('Passing')
        ->assertSee('No remote');
});

it('invites you to import the first project', function () {
    $this->get('/projects')->assertOk()->assertSee('No projects yet');
});

it('warns when GitHub Actions deploys the project too', function () {
    $project = Project::factory()->create([
        'detection' => ['workflows' => [(new WorkflowAnalyzer)->analyze('deploy.yml', fixtureText('workflows/ghcr-sha.yml'))]],
    ]);

    $this->get(route('projects.show', ['project' => $project, 'tab' => 'settings']))
        ->assertOk()
        ->assertSee('GitHub Actions deploys this project too')
        ->assertSee('deploy.yml runs deploy.sh over SSH on every push to main, master.');
});

it('calls out a green build no server is running yet, on the list and the project', function () {
    $target = demoTarget();
    $project = $target->project;
    $sha = str_repeat('c1', 20);
    $project->update([
        'ci_state' => CiState::Passing,
        'ci_run' => ['sha' => $sha, 'name' => 'CI/CD', 'number' => 413, 'url' => 'https://github.com/KielD-01/demo/actions/runs/413'],
    ]);
    $live = Release::factory()->create(['target_id' => $target->id, 'commit_sha' => str_repeat('b2', 20)]);
    $target->update(['current_release_id' => $live->id]);

    $this->get('/projects')->assertOk()->assertSee('New build ready');
    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee(substr($sha, 0, 7))
        ->assertSee('isn’t live on '.$target->server->name.' yet', false);

    // The deploy dialog opens on that commit.
    Livewire::test(DeployDialog::class, ['projectId' => $project->id])
        ->call('openFor', null, $sha)
        ->assertSet('commit', $sha);

    // Once it's live, there's nothing to call out.
    $target->update(['current_release_id' => Release::factory()->create(['target_id' => $target->id, 'commit_sha' => $sha])->id]);

    $this->get('/projects')->assertOk()->assertDontSee('New build ready');
    $this->get(route('projects.show', $project))->assertOk()->assertDontSee('isn’t live on', false);
});

it('saves the deploy settings, strategy order included', function () {
    $project = Project::factory()->create();

    Livewire::test(Show::class, ['project' => $project])
        ->set('tab', 'settings')
        ->set('keepReleases', 3)
        ->set('dockerized', true)
        ->set('stackName', 'demo')
        ->set('composeFile', 'compose.yaml')
        ->set('backupRole', 'postgres')
        ->set('backupKeep', 10)
        ->set('healthUrl', 'https://demo.example.com/up')
        ->set('healthCommand', '  php artisan app:smoke-test  ')
        ->call('sortStrategy', 'server_git', 0)
        ->call('toggleStrategy', 'server_git')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Settings saved.');

    expect($project->refresh())
        ->keep_releases->toBe(3)
        ->dockerized->toBeTrue()
        ->stack_name->toBe('demo')
        ->compose_files->toBe(['compose.yaml'])
        ->backup_role->toBe('postgres')
        ->backup_keep->toBe(10)
        ->health_url->toBe('https://demo.example.com/up')
        ->health_command->toBe('php artisan app:smoke-test')
        ->and(array_slice($project->delivery_strategies, 0, 2))->toBe([
            ['key' => 'server_git', 'enabled' => true],
            ['key' => 'push_archive', 'enabled' => true],
        ]);
});

it('corrects the image pins detection read badly, and goes back to them', function () {
    $project = Project::factory()->create([
        'dockerized' => true, 'stack_name' => 'demo', 'compose_files' => ['compose.yaml'],
        'github_owner' => 'KielD-01', 'github_repo' => 'demo',
        'detection' => [
            'workflows' => [[
                'file' => 'ci.yml', 'name' => 'CI', 'error' => null, 'jobs' => 1, 'deploys' => null,
                'triggers' => ['push' => ['main'], 'pull_request' => false, 'manual' => false, 'schedule' => false],
                'images' => [['image' => 'ghcr.io/{owner}/{repo}-{service}', 'tag' => 'sha-{sha7}', 'count' => 1]],
            ]],
            'compose' => [[
                'file' => 'compose.yaml', 'name' => 'demo', 'error' => null, 'services' => ['api'], 'app_services' => ['api'],
                'pins' => [['var' => 'API_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/kield-01/demo-api']],
            ]],
        ],
    ]);

    // Detection is only a seed: while it's untouched nothing is stored.
    $page = Livewire::test(Show::class, ['project' => $project])
        ->set('tab', 'settings')
        ->assertSet('imageTemplates', [['var' => 'API_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/kield-01/demo-api', 'tag' => 'sha-{sha7}']])
        ->call('save')
        ->assertHasNoErrors();

    expect($project->refresh()->image_templates)->toBeNull();

    // CI actually publishes one image under another name, pinned whole.
    $page->set('imageTemplates.0.var', 'API_IMAGE')
        ->set('imageTemplates.0.kind', 'image')
        ->set('imageTemplates.0.image', 'ghcr.io/kield-01/warehouse')
        ->set('imageTemplates.0.tag', 'build-{sha}')
        ->call('save')
        ->assertHasNoErrors();

    // jsonb doesn't keep the key order, so compare loosely.
    expect($project->refresh()->image_templates)->toEqual([
        ['var' => 'API_IMAGE', 'kind' => 'image', 'image' => 'ghcr.io/kield-01/warehouse', 'tag' => 'build-{sha}'],
    ])
        ->and(ImagePlan::for($project, str_repeat('ab', 20))?->pins)
        ->toBe(['API_IMAGE' => 'ghcr.io/kield-01/warehouse:build-'.str_repeat('ab', 20)]);

    $page->call('addImageTemplate')->set('imageTemplates.1.var', 'no spaces please')->call('save')->assertHasErrors('imageTemplates.1.var');

    $page->call('resetImageTemplates')->call('save')->assertHasNoErrors();
    expect($project->refresh()->image_templates)->toBeNull();
});

it('tests how each strategy would answer, with the settings as saved', function () {
    Credential::factory()->create(['login' => 'KielD-01']);
    $project = Project::factory()->create(['github_owner' => 'KielD-01', 'github_repo' => 'demo', 'branch' => 'main']);

    $page = Livewire::test(Show::class, ['project' => $project])
        ->set('tab', 'settings')
        ->assertSet('resolutionTrace', null)
        // Switched on in the form but not saved: the test goes by what's stored.
        ->call('toggleStrategy', 'server_git')
        ->call('testResolution');

    expect($page->get('resolutionTrace')[0])->toMatchArray(['key' => 'push_archive', 'state' => 'applicable'])
        ->and($page->get('resolutionTrace')[0]['reason'])->toBe('The latest commit on main, fetched from KielD-01/demo.')
        ->and(collect($page->get('resolutionTrace'))->firstWhere('key', 'server_git'))->toMatchArray(['state' => 'off']);

    $page->assertSee('applies')->assertSee('The latest commit on main, fetched from KielD-01/demo.');
});

it('refuses settings servers would reject', function () {
    Livewire::test(Show::class, ['project' => Project::factory()->create()])
        ->set('keepReleases', 11)
        ->set('dockerized', true)
        ->set('stackName', 'Bad.Name')
        ->set('composeFile', '/etc/passwd')
        ->set('branch', '--upload-pack=evil')
        ->set('backupRole', 'postgres; drop database')
        ->call('save')
        ->assertHasErrors(['keepReleases', 'stackName', 'composeFile', 'branch', 'backupRole']);
});

it('needs at least one delivery strategy', function () {
    Livewire::test(Show::class, ['project' => Project::factory()->create()])
        ->call('toggleStrategy', 'push_archive')
        ->call('save')
        ->assertHasErrors('strategies');
});

it('removes a project from deploy.me', function () {
    $project = Project::factory()->create();

    Livewire::test(Show::class, ['project' => $project])->call('archive')->assertRedirect(route('projects.index'));

    expect($project->refresh()->archived_at)->not->toBeNull();
    $this->get(route('projects.show', $project))->assertNotFound();
});

it('keeps env files encrypted and versioned, with secrets masked until you confirm', function () {
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
    $dir = tempProject(['src/api/.env.example' => "APP_NAME=Demo\nAPP_KEY=base64:original\n"]);
    $project = Project::factory()->local($dir)->create([
        'detection' => ['env_templates' => [['path' => 'src/api/.env.example', 'keys' => 2]]],
    ]);

    $environment = Livewire::test(Environment::class, ['projectId' => $project->id])
        ->call('startAdding')
        ->set('newPath', 'src/api/.env')
        ->set('template', 'src/api/.env.example')
        ->call('addFile')
        ->assertHasNoErrors()
        ->assertSee('Added src/api/.env from src/api/.env.example.')
        ->assertSee('Demo')
        ->assertDontSee('base64:original')
        ->call('reveal')
        ->assertRedirect(route('password.confirm'));

    session(['auth.password_confirmed_at' => time()]);

    $environment->call('edit')
        ->assertSet('editing', true)
        ->set('content', "APP_NAME=Demo\nAPP_KEY=base64:rotated\n")
        ->call('save')
        ->assertSee('Saved src/api/.env as v2.')
        ->assertSee('base64:rotated');

    $file = $project->envFiles()->sole();

    expect($file->version)->toBe(2)
        ->and($file->key_count)->toBe(2)
        ->and($file->versions()->sole()->content)->toContain('base64:original')
        ->and(DB::table('project_env_files')->value('content'))->not->toContain('rotated')
        ->and(DB::table('project_env_versions')->value('content'))->not->toContain('original');
});

it('adds a hook preset adapted to the project, without duplicates', function () {
    $project = Project::factory()->dockerized('demo')->create(['app_services' => ['api', 'worker'], 'migrate_service' => 'api']);

    Livewire::test(Hooks::class, ['projectId' => $project->id])
        ->call('addPreset', 'laravel')
        ->assertSee('Added 2 hooks from Laravel.')
        ->call('addPreset', 'laravel')
        ->assertSee('Those hooks are already here.')
        ->assertSee('compose run --rm api')
        ->assertSee('compose exec api');

    // deploy.me migrates this project itself, so the preset leaves that out.
    expect($project->hooks()->pluck('command')->all())->toBe(['php artisan optimize', 'php artisan queue:restart'])
        ->and($project->hooks()->where('phase', 'release')->pluck('run_in')->map->value->all())->toBe(['service_run'])
        ->and($project->hooks()->where('phase', 'after_activate')->pluck('command')->all())->toBe(['php artisan queue:restart']);
});

it('keeps the migrate hook for a project deploy.me doesn’t migrate itself', function () {
    $docker = Project::factory()->dockerized('demo')->create(['app_services' => ['api'], 'migrate_service' => null]);
    $plain = Project::factory()->create();

    expect(array_column(HookPresets::for('laravel', $docker), 'command'))->toContain('php artisan migrate --force')
        ->and(array_column(HookPresets::for('laravel', $plain), 'command'))->toContain('php artisan migrate --force');
});

it('adds, edits, moves and removes hooks', function () {
    $project = Project::factory()->create();

    $hooks = Livewire::test(Hooks::class, ['projectId' => $project->id])
        ->call('startAdding', 'release')
        ->set('hookName', 'Warm cache')
        ->set('command', 'php artisan cache:warm')
        ->set('timeout', 120)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Added Warm cache.')
        ->call('startAdding', 'release')
        ->set('hookName', 'Migrate')
        ->set('command', 'php artisan migrate --force')
        ->call('save');

    [$warm, $migrate] = $project->hooks()->get()->all();

    $hooks->call('move', $migrate->id, -1);
    expect($project->hooks()->pluck('name')->all())->toBe(['Migrate', 'Warm cache']);

    $hooks->call('edit', $warm->id)->set('continueOnError', true)->call('save')->assertHasNoErrors();
    expect($warm->refresh()->continue_on_error)->toBeTrue();

    $hooks->call('delete', $migrate->id);
    expect($project->hooks()->count())->toBe(1);
});

it('needs a service for hooks that run in a container', function () {
    Livewire::test(Hooks::class, ['projectId' => Project::factory()->create()->id])
        ->call('startAdding', 'release')
        ->set('hookName', 'Migrate')
        ->set('command', 'php artisan migrate --force')
        ->set('runIn', 'service_run')
        ->set('service', '')
        ->call('save')
        ->assertHasErrors('service');
});

it('asks again for a passkey once the confirmation is old, before showing another file’s values', function () {
    $project = Project::factory()->create();
    $first = $project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("APP_KEY=base64:first-secret\n")]);
    $second = $project->envFiles()->create(['path' => 'src/api/.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("APP_KEY=base64:second-secret\n")]);
    session(['auth.password_confirmed_at' => time()]);

    $environment = Livewire::test(Environment::class, ['projectId' => $project->id])
        ->call('select', $first->id)
        ->call('reveal')
        ->assertSee('base64:first-secret');

    // The tab stays open past the confirmation's lifetime (it goes by time(), not Carbon).
    session(['auth.password_confirmed_at' => time() - (int) config('auth.password_timeout', 10800) - 60]);

    $environment->call('select', $second->id)
        ->assertDontSee('base64:second-secret')
        ->call('reveal')
        ->assertRedirect(route('password.confirm'));
});

it('takes a commit for the deploy dialog only through its own pick', function () {
    $dialog = Livewire::test(DeployDialog::class, ['projectId' => Project::factory()->create()->id]);

    // Straight into the preview's GitHub requests otherwise.
    expect(fn () => $dialog->set('commit', '../../orgs/x'))->toThrow(CannotUpdateLockedPropertyException::class);

    $dialog->call('pickCommit', '../../orgs/x')->assertSet('commit', '')
        ->call('pickCommit', 'abc1234')->assertSet('commit', 'abc1234');
});
