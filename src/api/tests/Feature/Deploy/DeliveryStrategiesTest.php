<?php

use App\Actions\Deployments\CancelDeployment;
use App\Actions\Projects\PollCi;
use App\Deploy\Delivery\DeliveryResolver;
use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeliveryStrategyKey;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\StepStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Events\ProjectCiUpdated;
use App\Jobs\RunDeployment;
use App\Livewire\Deployments\Show as RunPage;
use App\Livewire\Projects\DeployDialog;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\ProjectEnvFile;
use App\Models\Release;
use App\Models\Target;
use App\Models\User;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 0,
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-m7-'.bin2hex(random_bytes(4)),
    ]);

    $this->ssh = fakeDeployScripts(new FakeSshClient);
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class, ProjectCiUpdated::class]);
    Process::fake(['*rsync*' => Process::result("Number of files: 3\nNumber of regular files transferred: 3\nTotal file size: 300 bytes\nTotal transferred file size: 300 bytes\n")]);
});

function repoHead(string $repo): string
{
    return trim(Process::path($repo)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
}

/**
 * A Docker project in a local repository that's also KielD-01/demo on
 * GitHub, where ci-cd.yml publishes ghcr.io/kield-01/demo-{api,web}:sha-{sha7}.
 */
function ghcrTarget(string $repo, array $project = []): Target
{
    return demoTarget([
        'source' => 'local', 'local_path' => $repo, 'github_owner' => 'KielD-01', 'github_repo' => 'demo',
        'dockerized' => true, 'stack_name' => 'demo', 'compose_files' => ['compose.yaml'],
        'app_services' => ['api', 'web'], 'migrate_service' => null, 'shared_paths' => [],
        'delivery_strategies' => [['key' => 'ghcr_image', 'enabled' => true], ['key' => 'push_archive', 'enabled' => true]],
        'detection' => [
            'workflows' => [[
                'file' => 'ci-cd.yml', 'name' => 'CI/CD', 'triggers' => ['push' => ['main']],
                'images' => [['image' => 'ghcr.io/{owner}/{repo}-{service}', 'tag' => 'sha-{sha7}', 'count' => 2]],
            ]],
            'compose' => [[
                'file' => 'compose.yaml', 'name' => 'demo', 'services' => ['api', 'web'], 'app_services' => ['api', 'web'],
                'pins' => [
                    ['var' => 'API_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/kield-01/demo-api'],
                    ['var' => 'WEB_IMAGE', 'kind' => 'image', 'image' => 'ghcr.io/kield-01/demo-web'],
                ],
            ]],
        ],
        ...$project,
    ]);
}

/**
 * GitHub and ghcr.io for KielD-01/demo, steered by $test->ci.
 */
function fakeGitHubCi(object $test, string $sha): void
{
    $test->ci = ['status' => 'completed', 'conclusion' => 'success', 'images' => true];

    Http::fake([
        'api.github.com/repos/KielD-01/demo/actions/runs/413/jobs*' => Http::response(['jobs' => [
            ['name' => 'Tests', 'status' => 'completed', 'conclusion' => 'success', 'started_at' => now()->subMinutes(5)->toIso8601String(), 'completed_at' => now()->subMinutes(1)->toIso8601String()],
            ['name' => 'Push to ghcr.io', 'status' => 'queued', 'conclusion' => null, 'started_at' => null, 'completed_at' => null],
        ]]),
        'api.github.com/repos/KielD-01/demo/commits*' => Http::response([['sha' => $sha, 'commit' => ['message' => 'Add PDF watermark option', 'committer' => ['date' => now()->toIso8601String()]]]]),
        'api.github.com/repos/KielD-01/demo/actions/runs*' => fn () => Http::response(['workflow_runs' => [[
            'id' => 413, 'name' => 'CI/CD', 'path' => '.github/workflows/ci-cd.yml', 'run_number' => 413, 'workflow_id' => 7,
            'status' => $test->ci['status'], 'conclusion' => $test->ci['conclusion'], 'event' => 'push', 'head_sha' => $sha,
            'html_url' => 'https://github.com/KielD-01/demo/actions/runs/413', 'updated_at' => now()->toIso8601String(),
            'head_commit' => ['message' => "Add PDF watermark option\n\nWith a toggle."],
        ]]]),
        'ghcr.io/token*' => Http::response(['token' => 'registry-token']),
        'ghcr.io/v2/*' => fn () => Http::response('', $test->ci['images'] ? 200 : 404),
    ]);
}

function strategyRun(Target $target, array $options = []): Deployment
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'options' => $options]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

function stepsMap(Deployment $deployment): array
{
    return $deployment->steps()->get()->mapWithKeys(fn ($step) => [$step->key => $step->status->value])->all();
}

function m7Var(RemoteScript $script, string $name): ?string
{
    return preg_match('/^'.preg_quote($name, '/').'=\$\(printf %s \'([^\']*)\' \| base64 -d/m', $script->render(), $match) === 1
        ? base64_decode($match[1])
        : null;
}

it('deploys the images CI published for the commit, pinned in the release', function () {
    $repo = tempGitRepo(['compose.yaml' => "services:\n  api:\n    image: ghcr.io/kield-01/demo-api:\${API_TAG}\n"]);
    $sha = repoHead($repo);
    $sha7 = substr($sha, 0, 7);
    $credential = Credential::factory()->create();
    fakeGitHubCi($this, $sha);
    $target = ghcrTarget($repo);
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'compose', ...ProjectEnvFile::contentAttributes("APP_KEY=base64:secret-key\nAPI_TAG=main\n")]);

    $deployment = strategyRun($target);
    $release = $deployment->release;

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and($deployment->strategy)->toBe('ghcr_image')
        ->and($deployment->strategy_trace[0]['reason'])->toBe("ci-cd.yml run #413 passed for {$sha7} · 2 images tagged sha-{$sha7} found.")
        ->and(stepsMap($deployment))->toMatchArray(['pins' => 'succeeded', 'login' => 'succeeded', 'pull' => 'succeeded', 'build' => 'skipped', 'recreate' => 'succeeded'])
        ->and($release->image_pins)->toBe(['API_TAG' => "sha-{$sha7}", 'WEB_IMAGE' => "ghcr.io/kield-01/demo-web:sha-{$sha7}"]);

    // The pins get a file of the release's own, read after the shared .env: nothing secret in it.
    $pins = collect($this->ssh->calls)->last(fn (array $call) => $call['script']->name === 'deploy-write-file')['script'];
    expect(m7Var($pins, 'FILE'))->toBe("{$release->path}/.deployme-pins.env")
        ->and(m7Var($pins, 'CONTENT'))->toBe("API_TAG=sha-{$sha7}\nWEB_IMAGE=ghcr.io/kield-01/demo-web:sha-{$sha7}\n");

    // The token reaches the server inside the script; the pull uses that login and drops it.
    $login = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-registry-login')['script'];
    $pull = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-compose' && m7Var($call['script'], 'ACTION') === 'pull')['script'];
    expect(m7Var($login, 'TOKEN'))->toBe($credential->token)
        ->and(m7Var($pull, 'DOCKER_CONFIG_DIR'))->toBe(m7Var($login, 'DIR'))
        ->and(collect($this->ssh->calls)->pluck('script.name')->all())->not->toContain('deploy-forget-login')
        ->and($deployment->logLines()->pluck('line')->implode("\n"))->not->toContain($credential->token);
});

it('falls back to the next strategy when CI failed for the commit', function () {
    $repo = tempGitRepo(['compose.yaml' => "services: {}\n"]);
    $sha = repoHead($repo);
    Credential::factory()->create();
    fakeGitHubCi($this, $sha);
    $this->ci['conclusion'] = 'failure';

    $resolution = app(DeliveryResolver::class)->resolve(ghcrTarget($repo)->project);

    expect($resolution->strategy)->toBe(DeliveryStrategyKey::PushArchive)
        ->and($resolution->trace[0]['reason'])->toBe('ci-cd.yml run #413 for '.substr($sha, 0, 7).' failed.');
});

it('says a check failed instead of saying the project doesn’t qualify', function () {
    $repo = tempGitRepo(['compose.yaml' => "services: {}\n"]);
    $sha = repoHead($repo);
    Credential::factory()->create();
    Http::fake([
        'api.github.com/repos/KielD-01/demo/commits*' => Http::response([['sha' => $sha, 'commit' => ['message' => 'Add PDF watermark option', 'committer' => ['date' => now()->toIso8601String()]]]]),
        // The run lookup fails: nothing was learnt about the images.
        'api.github.com/repos/KielD-01/demo/actions/runs*' => Http::response(['message' => 'Bad credentials'], 401),
    ]);
    $target = ghcrTarget($repo);

    $resolution = app(DeliveryResolver::class)->resolve($target->project);

    expect($resolution->strategy)->toBe(DeliveryStrategyKey::PushArchive)
        ->and($resolution->trace[0])->toMatchArray(['key' => 'ghcr_image', 'state' => 'error'])
        ->and($resolution->trace[0]['reason'])->toBe('GitHub didn’t answer: GitHub rejected the token. It may have expired or been revoked.')
        ->and($resolution->errors())->toHaveCount(1);

    // Nothing about a failed call is remembered: the next look asks GitHub again.
    app(DeliveryResolver::class)->resolve($target->project);
    expect(collect(Http::recorded())->filter(fn (array $call) => str_contains($call[0]->url(), '/actions/runs'))->count())->toBe(2);

    // The dialog says so, and doesn't dress it up as "doesn't apply".
    Livewire::actingAs(User::factory()->create())->test(DeployDialog::class, ['projectId' => $target->project_id])
        ->call('openFor', $target->id)
        ->assertSee('Couldn’t check')
        ->assertSee('was passed over because its check failed')
        ->assertDontSee('ghcr.io images doesn’t apply');
});

it('waits while CI is still building the images, then goes ahead', function () {
    Queue::fake();
    $repo = tempGitRepo(['compose.yaml' => "services: {}\n"]);
    $sha = repoHead($repo);
    Credential::factory()->create();
    fakeGitHubCi($this, $sha);
    $this->ci = ['status' => 'in_progress', 'conclusion' => null, 'images' => false];
    $target = ghcrTarget($repo);

    $waiting = strategyRun($target);

    expect($waiting->status)->toBe(DeploymentStatus::WaitingCi)
        ->and($waiting->strategy)->toBeNull()
        ->and($waiting->strategy_trace[0])->toMatchArray(['key' => 'ghcr_image', 'state' => 'pending'])
        ->and($waiting->strategy_trace[0]['run']['number'])->toBe(413)
        ->and($waiting->strategy_trace[1])->toMatchArray(['key' => 'push_archive', 'state' => 'applicable'])
        ->and($waiting->steps()->where('key', 'resolve')->first()->status)->toBe(StepStatus::Pending)
        ->and(Release::query()->count())->toBe(0);

    // Nothing on the server was touched, not even the lock.
    $this->ssh->assertNothingRan();
    Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deploymentId === $waiting->id && $job->delay !== null);

    // CI finishes: the next check deploys.
    Cache::flush();
    $this->ci = ['status' => 'completed', 'conclusion' => 'success', 'images' => true];
    (new RunDeployment($waiting->id))->handle(app(PipelineRunner::class));

    expect($waiting->refresh()->status)->toBe(DeploymentStatus::Succeeded, (string) $waiting->outcome)
        ->and($waiting->strategy)->toBe('ghcr_image');
});

it('starts a run from the deploy dialog that waits for CI', function () {
    Queue::fake();
    $repo = tempGitRepo(['compose.yaml' => "services: {}\n"]);
    $sha = repoHead($repo);
    Credential::factory()->create();
    fakeGitHubCi($this, $sha);
    $this->ci = ['status' => 'in_progress', 'conclusion' => null, 'images' => false];
    $target = ghcrTarget($repo);

    Livewire::actingAs(User::factory()->create())->test(DeployDialog::class, ['projectId' => $target->project_id])
        ->call('openFor', $target->id)
        // Nothing applies yet, but the button says what it will do rather than being dead.
        ->assertSee('when CI passes')
        ->call('deploy')
        ->assertHasNoErrors()
        ->assertRedirect();

    $deployment = Deployment::query()->sole();
    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::WaitingCi)
        ->and($deployment->strategy_trace[0])->toMatchArray(['key' => 'ghcr_image', 'state' => 'pending']);
    $this->ssh->assertNothingRan();
});

it('keeps the deploy button dead when nothing can deliver the project', function () {
    Queue::fake();
    $repo = tempGitRepo(['compose.yaml' => "services: {}\n"]);
    $sha = repoHead($repo);
    Credential::factory()->create();
    fakeGitHubCi($this, $sha);
    $this->ci['conclusion'] = 'failure';
    // Only ghcr.io images, and CI failed for the commit: there's no fallback.
    $target = ghcrTarget($repo, ['delivery_strategies' => [['key' => 'ghcr_image', 'enabled' => true]]]);

    Livewire::actingAs(User::factory()->create())->test(DeployDialog::class, ['projectId' => $target->project_id])
        ->call('openFor', $target->id)
        ->assertSee('Nothing can deliver this project right now.')
        ->assertDontSee('when CI passes');

    expect(Deployment::query()->count())->toBe(0);
});

it('goes ahead with the next strategy when you don’t want to wait', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $repo = tempGitRepo(['compose.yaml' => "services: {}\n"]);
    Credential::factory()->create();
    fakeGitHubCi($this, repoHead($repo));
    $this->ci = ['status' => 'in_progress', 'conclusion' => null, 'images' => false];
    $waiting = strategyRun(ghcrTarget($repo));

    Livewire::test(RunPage::class, ['deployment' => $waiting])
        ->assertSee('Why it’s waiting')
        ->assertSee(['GHCR image', 'is pending', 'Push archive', 'applies'])
        ->assertSee(['ci-cd.yml · run #413', 'Tests', 'Push to ghcr.io'])
        ->call('deployNow');

    expect($waiting->refresh()->options)->toBe(['strategy' => 'push_archive']);
    Queue::assertPushed(RunDeployment::class, 2);

    (new RunDeployment($waiting->id))->handle(app(PipelineRunner::class));

    expect($waiting->refresh()->status)->toBe(DeploymentStatus::Succeeded, (string) $waiting->outcome)
        ->and($waiting->strategy)->toBe('push_archive')
        ->and($waiting->strategy_trace[0])->toMatchArray(['key' => 'ghcr_image', 'state' => 'not_needed']);
});

it('gives up on CI after an hour', function () {
    Queue::fake();
    $repo = tempGitRepo(['compose.yaml' => "services: {}\n"]);
    Credential::factory()->create();
    fakeGitHubCi($this, repoHead($repo));
    $this->ci = ['status' => 'in_progress', 'conclusion' => null, 'images' => false];
    $deployment = Deployment::factory()->create(['target_id' => ghcrTarget($repo)->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::WaitingCi]);
    $deployment->forceFill(['created_at' => now()->subMinutes(61)])->save();

    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toStartWith('CI didn’t publish anything within an hour.');
    Queue::assertNothingPushed();
});

it('cancels a run that waits for CI on the spot', function () {
    $deployment = Deployment::factory()->create(['status' => DeploymentStatus::WaitingCi]);

    app(CancelDeployment::class)($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Cancelled);
});

it('lets the server fetch the commit itself', function () {
    $repo = tempGitRepo(['index.php' => 'v1']);
    $target = demoTarget([
        'source' => 'local', 'local_path' => $repo, 'github_owner' => 'KielD-01', 'github_repo' => 'demo',
        'git_remote' => 'https://github.com/KielD-01/demo.git',
        'delivery_strategies' => [['key' => 'server_git', 'enabled' => true]],
    ]);
    $sha = str_repeat('e', 40);
    fakeDeployScripts($this->ssh, ['deploy-server-git' => FakeSshClient::ok("✓ eeeeeee Tags page unpacked\n::deployme::commit={$sha}\n::deployme::subject=Tags page\n")]);

    $deployment = strategyRun($target);
    $fetch = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-server-git')['script'];

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and(m7Var($fetch, 'REMOTE'))->toBe('git@github.com:KielD-01/demo.git')
        ->and(m7Var($fetch, 'MIRROR'))->toBe('/opt/srv/demo/.deploy/repo.git')
        ->and(m7Var($fetch, 'REF'))->toBe('main')
        ->and(m7Var($fetch, 'DEST'))->toBe($deployment->release->path)
        ->and($deployment->release->commit_sha)->toBe($sha)
        ->and($deployment->commit_message)->toBe('Tags page');

    Process::assertDidntRun(fn ($process) => str_contains(implode(' ', (array) $process->command), 'rsync'));
});

it('deploys by itself when CI passes on the branch, once per commit', function () {
    Queue::fake();
    Credential::factory()->create();
    $sha = str_repeat('b', 40);
    Http::fake([
        'api.github.com/repos/KielD-01/demo/git/ref/heads/main' => Http::response(['object' => ['sha' => $sha]]),
        'api.github.com/repos/KielD-01/demo/actions/runs*' => Http::response(['workflow_runs' => [[
            'id' => 9, 'name' => 'CI', 'path' => '.github/workflows/ci.yml', 'run_number' => 9, 'workflow_id' => 1,
            'status' => 'completed', 'conclusion' => 'success', 'head_sha' => $sha,
        ]]], 200, ['ETag' => 'W/"1"']),
    ]);
    $target = demoTarget(['github_owner' => 'KielD-01', 'github_repo' => 'demo', 'auto_deploy' => true]);

    app(PollCi::class)($target->project);
    app(PollCi::class)($target->project->refresh());

    $run = Deployment::query()->sole();
    expect($run->trigger)->toBe('ci')
        ->and($run->kind)->toBe(DeploymentKind::Deploy)
        ->and($run->options)->toBe(['commit' => $sha]);
});
