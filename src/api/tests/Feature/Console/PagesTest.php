<?php

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseStatus;
use App\Enums\TargetHealth;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Jobs\RunDeployment;
use App\Livewire\Console\Git;
use App\Livewire\Deployments\Show as RunPage;
use App\Livewire\Projects\DeployDialog;
use App\Livewire\Projects\Show as ProjectPage;
use App\Livewire\Servers\Show as ServerPage;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\Target;
use App\Models\TargetHealthCheck;
use App\Models\User;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use App\Support\Ui;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// The Console theme's own run, server and project pages: the same components as
// Classic's, with views (resources/views/themes/console/livewire) of their own.

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    Ui::reset();
    Queue::fake();
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
});

/**
 * Livewire::test skips the middleware that applies someone's theme: these
 * render the Console theme's views, as a console user's page would.
 */
function inConsole(): void
{
    Ui::apply('console');
}

/**
 * What a page's view wires to its component: the methods it calls, the
 * properties it binds, the tabs it switches to and the events it sends,
 * following its @includes the way the theme would find them.
 *
 * @return list<string>
 */
function wiredActions(string $view, bool $console): array
{
    $find = function (string $name) use ($console): string {
        $path = str_replace('.', '/', $name).'.blade.php';
        $themed = resource_path("views/themes/console/{$path}");

        return $console && is_file($themed) ? $themed : resource_path("views/{$path}");
    };

    $found = [];
    $read = function (string $name) use (&$read, &$found, $find): void {
        $source = file_get_contents($find($name));

        preg_match_all('/wire:(?:click|submit)(?:\.[\w-]+)*="\$set\(\'(\w+)\'/', $source, $sets);
        preg_match_all('/wire:(?:click|submit)(?:\.[\w-]+)*="(\w+)/', $source, $calls);
        preg_match_all('/wire:model(?:\.[\w-]+)*="(\w+)/', $source, $models);
        preg_match_all('/wire:sort="(\w+)"/', $source, $sorts);
        preg_match_all('/\$(?:wire\.)?dispatch\(\'([\w-]+)\'/', $source, $events);
        preg_match_all('/<livewire:([\w.-]+)/', $source, $children);

        array_push($found,
            ...array_map(fn ($name) => "set {$name}", $sets[1]),
            ...array_map(fn ($name) => "call {$name}", $calls[1]),
            ...array_map(fn ($name) => "model {$name}", $models[1]),
            ...array_map(fn ($name) => "call {$name}", $sorts[1]),
            ...array_map(fn ($name) => "event {$name}", $events[1]),
            ...array_map(fn ($name) => "component {$name}", $children[1]),
        );

        preg_match_all('/@include\(\'([\w.-]+)\'/', $source, $includes);
        foreach ($includes[1] as $include) {
            $read($include);
        }
    };
    $read($view);

    return array_values(array_unique($found));
}

it('wires everything the classic pages do to the same components', function (string $view) {
    $classic = wiredActions($view, console: false);

    expect($classic)->not->toBeEmpty()
        ->and(array_values(array_diff($classic, wiredActions($view, console: true))))->toBe([]);
})->with(['livewire.deployments.show', 'livewire.servers.show', 'livewire.projects.show']);

// The run page.

function consoleRun(): Deployment
{
    fakeDeployScripts(test()->ssh);
    $run = Deployment::factory()->create(['target_id' => demoTarget()->id]);
    app(PipelineRunner::class)->run($run);

    return $run->refresh();
}

it('shows a console user the run as a terminal, and a classic one the classic page', function () {
    $run = consoleRun();

    $this->get(route('deployments.show', $run))
        ->assertOk()
        ->assertSee('tail -f deploy.log')
        ->assertSee(['data-fold="preflight"', 'data-log-step="preflight"', 'class="con-progress"', 'x-data="deployLog('], false)
        ->assertSee(['Pre-flight checks', '97.7 GB free', 'Succeeded', '/opt/srv/demo is ready for deploys.'])
        ->assertDontSee('All steps');

    asClassic();
    $this->get(route('deployments.show', $run))
        ->assertOk()
        ->assertSee(['All steps', 'Pre-flight checks', 'Mode'])
        ->assertDontSee('tail -f deploy.log');
});

it('folds open the step that failed, and leaves the ones that passed folded', function () {
    $run = Deployment::factory()->create(['target_id' => demoTarget()->id, 'status' => DeploymentStatus::Failed]);
    $run->steps()->create(['key' => 'preflight', 'label' => 'Pre-flight checks', 'position' => 0, 'status' => 'succeeded']);
    $run->steps()->create(['key' => 'migrate', 'label' => 'Migrate', 'position' => 1, 'status' => 'failed', 'detail' => 'exit code 1']);
    inConsole();

    Livewire::test(RunPage::class, ['deployment' => $run])
        ->assertSeeHtml('data-fold="migrate" open')
        ->assertDontSeeHtml('data-fold="preflight" open')
        ->assertSee('exit code 1');
});

it('cancels a running run from the console page', function () {
    $run = Deployment::factory()->create(['status' => DeploymentStatus::Running]);
    inConsole();

    Livewire::test(RunPage::class, ['deployment' => $run])
        ->assertSeeHtml('data-key="c" wire:click="cancel"')
        ->call('cancel')
        ->assertSee('Cancelling…');

    expect($run->refresh()->cancel_requested_at)->not->toBeNull();
});

it('runs a failed deploy again, or picks it up from the step that failed', function () {
    $target = demoTarget();
    $previous = Release::factory()->create(['target_id' => $target->id, 'name' => '20260901-100000']);
    $release = Release::factory()->create(['target_id' => $target->id, 'name' => '20260902-100000', 'status' => ReleaseStatus::Failed]);
    $target->update(['current_release_id' => $previous->id]);
    $run = Deployment::factory()->create([
        'target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::RolledBack,
        'strategy' => 'push_archive', 'commit_sha' => str_repeat('b', 40), 'options' => ['backup' => true],
        'release_id' => $release->id, 'previous_release_id' => $previous->id,
    ]);
    foreach (['resolve' => 'Resolve commit', 'upload' => 'Upload files', 'activate' => 'Switch current', 'health' => 'Health check', 'rollback' => 'Switch back', 'unlock' => 'Clean up'] as $key => $label) {
        $run->steps()->create(['key' => $key, 'label' => $label, 'position' => $run->steps()->count(), 'status' => $key === 'health' ? 'failed' : 'succeeded']);
    }
    inConsole();

    Livewire::test(RunPage::class, ['deployment' => $run])
        ->assertSee(['retry from health check', 'deploy bbbbbbb again'])
        ->call('retryFrom')
        ->assertRedirect();
    $resumed = Deployment::query()->latest('id')->first();
    expect($resumed)->retry_of_id->toBe($run->id)->resume_from->toBe('health');
    $resumed->update(['status' => DeploymentStatus::Failed]);

    Livewire::test(RunPage::class, ['deployment' => $run->refresh()])
        ->call('retry')
        ->assertRedirect();
    expect(Deployment::query()->latest('id')->first()->options)->toBe(['backup' => true, 'commit' => str_repeat('b', 40)]);
});

it('asks before a single key starts a run on the server', function () {
    // The tag of the button a page key clicks.
    $button = function (string $html, string $key): string {
        preg_match('/<button[^>]*data-key="'.preg_quote($key, '/').'"[^>]*>/', $html, $match);

        return $match[0] ?? '';
    };
    $target = demoTarget();
    $release = Release::factory()->create(['target_id' => $target->id, 'name' => '20260902-100000', 'status' => ReleaseStatus::Failed]);
    $failed = Deployment::factory()->create([
        'target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Failed,
        'strategy' => 'push_archive', 'commit_sha' => str_repeat('b', 40), 'release_id' => $release->id,
    ]);
    foreach (['resolve' => 'succeeded', 'upload' => 'failed', 'unlock' => 'succeeded'] as $key => $status) {
        $failed->steps()->create(['key' => $key, 'label' => ucfirst($key), 'position' => $failed->steps()->count(), 'status' => $status]);
    }
    $waiting = Deployment::factory()->create([
        'target_id' => Target::factory()->create(['project_id' => $target->project_id])->id, 'status' => DeploymentStatus::WaitingCi,
        'strategy_trace' => [
            ['key' => 'ghcr_image', 'label' => 'GHCR image', 'state' => 'pending', 'reason' => 'CI is still running.'],
            ['key' => 'push_archive', 'label' => 'Push archive', 'state' => 'applicable', 'reason' => 'The commit, as an archive.'],
        ],
    ]);
    inConsole();

    $html = Livewire::test(RunPage::class, ['deployment' => $failed])->html();
    expect($button($html, 'R'))->toContain('wire:click="retryFrom"')->toContain('wire:confirm=')
        ->and($button($html, 'r'))->toContain('wire:click="retry"')->toContain('wire:confirm=');

    expect($button(Livewire::test(RunPage::class, ['deployment' => $waiting])->html(), 'n'))
        ->toContain('wire:click="deployNow"')->toContain('wire:confirm=');
});

it('rolls back to the release a deploy replaced', function () {
    $target = demoTarget();
    $previous = Release::factory()->create(['target_id' => $target->id, 'name' => '20260901-100000']);
    $new = Release::factory()->create(['target_id' => $target->id, 'name' => '20260902-100000']);
    $target->update(['current_release_id' => $new->id, 'previous_release_id' => $previous->id]);
    $run = Deployment::factory()->create([
        'target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Succeeded,
        'release_id' => $new->id, 'previous_release_id' => $previous->id,
    ]);
    inConsole();

    Livewire::test(RunPage::class, ['deployment' => $run])
        ->assertSee('roll back to 20260901-100000')
        ->call('rollBack')
        ->assertRedirect();

    expect(Deployment::query()->latest('id')->first())->kind->toBe(DeploymentKind::Rollback);
});

it('goes ahead with the next strategy instead of waiting for CI', function () {
    $run = Deployment::factory()->create([
        'target_id' => demoTarget()->id, 'status' => DeploymentStatus::WaitingCi,
        'strategy_trace' => [
            ['key' => 'ghcr_image', 'label' => 'GHCR image', 'state' => 'pending', 'reason' => 'CI is still running.'],
            ['key' => 'push_archive', 'label' => 'Push archive', 'state' => 'applicable', 'reason' => 'The commit, as an archive.'],
        ],
    ]);
    inConsole();

    Livewire::test(RunPage::class, ['deployment' => $run])
        ->assertSee(['waiting for CI', 'GHCR image is pending', 'CI is still running.', 'deploy now with push archive'])
        ->call('deployNow');

    expect($run->refresh()->options)->toBe(['strategy' => 'push_archive']);
    Queue::assertPushed(RunDeployment::class);
});

it('undoes a conversion, and removes its backup once you confirm', function () {
    $target = conversionTarget();
    $run = Deployment::factory()->create([
        'target_id' => $target->id, 'kind' => DeploymentKind::Convert, 'status' => DeploymentStatus::Succeeded,
        'options' => ['backup' => '.deploy/convert-20260911-120000/old'],
    ]);
    $release = Release::factory()->create(['target_id' => $target->id, 'deployment_id' => $run->id, 'name' => '20260911-120000', 'layout' => Release::IN_RELEASES]);
    $run->update(['release_id' => $release->id]);
    $target->update(['current_release_id' => $release->id]);
    inConsole();

    Livewire::test(RunPage::class, ['deployment' => $run->refresh()])
        ->assertSee(['undo conversion', '.deploy/convert-20260911-120000/old', 'remove backup'])
        ->call('removeBackup')
        ->assertRedirect(route('password.confirm'));

    session(['auth.password_confirmed_at' => time()]);
    $this->ssh->on('box', 'deploy-convert', FakeSshClient::ok("✓ Removed .deploy/convert-20260911-120000/old\n"));
    Livewire::test(RunPage::class, ['deployment' => $run->refresh()])
        ->call('removeBackup')
        ->assertSee('Removed .deploy/convert-20260911-120000/old.')
        ->assertDontSee('remove backup');

    Livewire::test(RunPage::class, ['deployment' => $run->refresh()])->call('undoConversion')->assertRedirect();
    expect(Deployment::query()->latest('id')->first()->kind)->toBe(DeploymentKind::UndoConvert);
});

// The server page.

function htopServer(): Server
{
    $server = Server::factory()->create(['name' => 'Build box', 'ssh_alias' => 'box', 'default_deploy_path' => '/opt/srv']);
    $server->snapshots()->create([
        'captured_at' => now()->subHour(),
        'cpu_usage' => 30, 'mem_total' => 1000, 'mem_available' => 500,
    ]);
    $server->snapshots()->create([
        'captured_at' => now(),
        'cpu_usage' => 12.5, 'mem_total' => 1000, 'mem_available' => 400, 'swap_total' => 200, 'swap_free' => 150,
        'load_1' => 0.5, 'load_5' => 0.4, 'load_15' => 0.3, 'uptime' => 86400, 'ssh_latency_ms' => 38,
        'disks' => [['source' => '/dev/sda1', 'fstype' => 'ext4', 'size' => 1000, 'used' => 390, 'available' => 610, 'mount' => '/']],
        'stacks' => [
            ['Name' => 'infra', 'Status' => 'running(3)', 'ConfigFiles' => '/opt/infra/compose.yaml'],
            ['Name' => 'example', 'Status' => 'running(5)', 'ConfigFiles' => '/opt/srv/example/compose.prod.yml'],
        ],
        'processes' => [
            'cpu' => [['pid' => 41, 'name' => 'frankenphp', 'container' => 'shop-web-1', 'cpu' => 84.0]],
            'mem' => [['pid' => 41, 'name' => 'frankenphp', 'container' => 'shop-web-1', 'rss' => 300_000_000], ['pid' => 7, 'name' => 'postgres', 'container' => 'infra-postgres-1', 'rss' => 2_000_000_000]],
            'net' => [],
            'net_note' => 'sudo needs a password',
        ],
    ]);

    return $server;
}

it('shows a console user the server as htop, and a classic one the classic page', function () {
    $server = htopServer();
    $target = Target::factory()->create(['server_id' => $server->id, 'project_id' => Project::factory()->create(['name' => 'shop'])->id]);
    TargetHealthCheck::query()->create(['target_id' => $target->id, 'health' => TargetHealth::Healthy, 'detail' => '200', 'duration_ms' => 63, 'checked_at' => now()->subMinute()]);
    TargetHealthCheck::query()->create(['target_id' => $target->id, 'health' => TargetHealth::Unhealthy, 'detail' => '502', 'duration_ms' => 12, 'checked_at' => now()]);

    $this->get(route('servers.show', $server))
        ->assertOk()
        ->assertSee(['watch probe', 'Build box', 'Probe now', 'docker compose ls', 'frankenphp', 'shop-web-1', 'postgres', 'Not measured: sudo needs a password.', '84.0', 'checks', 'shop'])
        ->assertSee('aria-label="1 of 2 healthy"', false)
        ->assertDontSee('Busiest processes</h2>', false);

    asClassic();
    $this->get(route('servers.show', $server))
        ->assertOk()
        ->assertSee(['Busiest processes', 'Probe now'])
        ->assertDontSee('watch probe');
});

it('renders every tab of the server page in the console', function (string $tab) {
    $server = htopServer();
    TargetHealthCheck::query()->create([
        'target_id' => Target::factory()->create(['server_id' => $server->id])->id,
        'health' => TargetHealth::Healthy, 'detail' => '200', 'duration_ms' => 20, 'checked_at' => now(),
    ]);
    inConsole();

    $page = Livewire::test(ServerPage::class, ['server' => $server])
        ->set('tab', $tab)
        ->assertOk()
        ->assertSet('tab', $tab);

    // The tab bar marks the open one with a star, like tmux's status line.
    expect(preg_replace('/\s+/', ' ', strip_tags($page->html())))->toMatch('/\d:\w+\*/');
})->with(['overview', 'stacks', 'checks', 'folders', 'settings']);

it('probes, saves, adopts and removes from the console server page', function () {
    $server = htopServer();
    $project = Project::factory()->create(['slug' => 'example', 'name' => 'example.dev', 'versionable' => false]);
    $this->ssh->on('box', 'ping', FakeSshClient::ok())->on('box', 'probe', probeOutput());
    inConsole();

    Livewire::test(ServerPage::class, ['server' => $server->refresh()])
        ->set('tab', 'settings')
        ->set('name', 'Renamed')
        ->set('envTag', 'staging')
        ->call('saveSettings')
        ->assertSee('Settings saved.');
    expect($server->refresh()->name)->toBe('Renamed');

    Livewire::test(ServerPage::class, ['server' => $server])
        ->set('tab', 'stacks')
        ->assertSee('Adopt into example.dev')
        ->assertDontSee('Adopt into infra')
        ->call('adoptStack', 'example')
        ->assertRedirect();
    expect($project->targets()->sole()->latestDeployment->kind)->toBe(DeploymentKind::Adopt);

    Livewire::test(ServerPage::class, ['server' => $server])
        ->call('probeNow')
        ->assertOk();
    expect($server->snapshots()->count())->toBe(3);

    Livewire::test(ServerPage::class, ['server' => $server])->call('archive')->assertRedirect(route('servers.index'));
    expect($server->refresh()->archived_at)->not->toBeNull();
});

it('opens the folder dialogs from the console server page', function () {
    $server = htopServer();
    $this->ssh->on('box', 'nearest-dir', FakeSshClient::ok('/opt/srv'))
        ->on('box', 'ls', lsOutput('/opt/srv', [['app', 'deploy', '755', true]], writable: true));
    inConsole();

    Livewire::test(ServerPage::class, ['server' => $server])
        ->call('browse')
        ->assertSet('browsing', true)
        ->assertSee('Browse files')
        ->set('browsing', false)
        ->set('tab', 'settings')
        ->call('choosePath')
        ->assertSet('choosingPath', true)
        ->assertSee('Use /opt/srv')
        ->call('usePath')
        ->assertSet('choosingPath', false);
});

// The project page.

/**
 * A local project with two commits on a server: the first one live, the
 * second its rollback target's.
 *
 * @return array{0: Target, 1: string, 2: string}
 */
function graphTarget(): array
{
    $repo = tempGitRepo(['index.php' => 'v1']);
    commitFile($repo, 'index.php', 'v2');
    $log = Process::path($repo)->env(['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'])->run(['git', 'log', '--format=%H'])->throw();
    [$head, $first] = explode("\n", trim($log->output()));

    $target = demoTarget(['source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null]);
    $old = Release::factory()->create(['target_id' => $target->id, 'name' => '20260901-100000', 'commit_sha' => $first]);
    $live = Release::factory()->create(['target_id' => $target->id, 'name' => '20260902-100000', 'commit_sha' => $head]);
    $target->update(['current_release_id' => $live->id, 'previous_release_id' => $old->id]);

    return [$target, $head, $first];
}

it('shows a console user the project as git log --graph, and a classic one the classic page', function () {
    [$target, $head, $first] = graphTarget();
    // The commits load after the page (a deferred pane); here they render with it.
    Livewire::withoutLazyLoading();

    $this->get(route('projects.show', $target->project))
        ->assertOk()
        ->assertSee(['git log --graph main', 'deploy.conf', 'layout', 'releases, keep 5', 'push_archive', 'wait_for_ci'])
        ->assertSee([substr($head, 0, 7), $target->server->slug.' ● live', substr($first, 0, 7), '20260901-100000'])
        ->assertDontSee('tabs tabs-border', false);

    asClassic();
    $this->get(route('projects.show', $target->project))
        ->assertOk()
        ->assertSee('tabs tabs-border', false)
        ->assertDontSee('git log --graph');
});

it('tags the commit a rollback would go back to, and offers to deploy each commit', function () {
    [$target, $head, $first] = graphTarget();
    inConsole();

    Livewire::test(Git::class, ['arg' => 'demo', 'page' => true])
        ->assertSee('20260901-100000 · ↺ '.$target->server->slug.' rolls back here')
        ->assertSee(['Deploy '.substr($head, 0, 7), 'Deploy '.substr($first, 0, 7)]);

    // What a commit's button asks for: the deploy dialog, opened on that commit.
    foreach ([$head, $first] as $sha) {
        Livewire::test(DeployDialog::class, ['projectId' => $target->project_id])
            ->call('openFor', $target->id, $sha)
            ->assertSet('open', true)
            ->assertSet('commit', $sha);
    }
});

it('renders every tab of the project page in the console', function (string $tab) {
    [$target] = graphTarget();
    inConsole();

    $page = Livewire::test(ProjectPage::class, ['project' => $target->project])
        ->set('tab', $tab)
        ->assertOk()
        ->assertSet('tab', $tab);

    expect(preg_replace('/\s+/', ' ', strip_tags($page->html())))->toMatch('/\d:\w+\*/');
})->with(['targets', 'releases', 'environment', 'hooks', 'settings']);

it('edits the settings from the console project page', function () {
    [$target] = graphTarget();
    $project = $target->project;
    inConsole();

    $page = Livewire::test(ProjectPage::class, ['project' => $project, 'tab' => 'settings'])
        ->set('name', 'Demo app')
        ->call('toggleStrategy', 'server_git')
        ->call('sortStrategy', 'server_git', 0)
        ->call('addImageTemplate')
        ->call('removeImageTemplate', 0)
        ->call('resetImageTemplates')
        ->call('testResolution')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Settings saved.');

    expect($page->get('resolutionTrace'))->not->toBeEmpty()
        ->and($project->refresh()->name)->toBe('Demo app')
        ->and($project->delivery_strategies[0])->toBe(['key' => 'server_git', 'enabled' => true]);

    Livewire::test(ProjectPage::class, ['project' => $project])->call('redetect')->assertSee('Detection refreshed.');
    Livewire::test(ProjectPage::class, ['project' => $project])->call('archive')->assertRedirect(route('projects.index'));
    expect($project->refresh()->archived_at)->not->toBeNull();
});

it('checks CI from the console project page', function () {
    fakeGitHubRepository();
    Http::fake(['api.github.com/repos/KielD-01/demo/git/ref/heads/main' => Http::response(['object' => ['sha' => 'abc1234def']])]);
    Credential::factory()->create();
    $project = Project::factory()->create(['github_owner' => 'KielD-01', 'github_repo' => 'demo']);
    inConsole();

    Livewire::test(ProjectPage::class, ['project' => $project])
        ->assertSee('check ci')
        ->call('checkCi')
        ->assertSee('ci passing');
});
