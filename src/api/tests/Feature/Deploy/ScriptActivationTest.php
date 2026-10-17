<?php

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\Target;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config([
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 0,
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-script-'.bin2hex(random_bytes(4)),
    ]);

    $this->ssh = fakeDeployScripts(new FakeSshClient, ['deploy-forget-login' => FakeSshClient::ok("✓ Forgot this deploy's registry login\n")]);
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
    Process::fake(['*rsync*' => Process::result("Number of files: 2\nNumber of regular files transferred: 2\nTotal file size: 200 bytes\nTotal transferred file size: 200 bytes\n")]);
});

/**
 * example.dev's shape: CI publishes ghcr.io/kield-01/site:{sha}, and the
 * repository's deploy.sh switches between two colors itself.
 */
function blueGreenTarget(): Target
{
    $repo = tempGitRepo([
        'compose.prod.yaml' => "services:\n  app-blue:\n    image: ghcr.io/kield-01/site:\${IMAGE_TAG:-latest}\n",
        'deploy.sh' => "#!/usr/bin/env bash\n",
    ]);
    $sha = trim(Process::path($repo)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());

    Http::fake([
        'api.github.com/repos/KielD-01/site/commits*' => Http::response([['sha' => $sha, 'commit' => ['message' => 'Category colors', 'committer' => ['date' => now()->toIso8601String()]]]]),
        'api.github.com/repos/KielD-01/site/actions/runs*' => Http::response(['workflow_runs' => [[
            'id' => 42, 'name' => 'Deploy', 'path' => '.github/workflows/deploy.yml', 'run_number' => 42, 'workflow_id' => 1,
            'status' => 'completed', 'conclusion' => 'success', 'event' => 'push', 'head_sha' => $sha, 'head_commit' => ['message' => 'Category colors'],
        ]]]),
        'ghcr.io/token*' => Http::response(['token' => 'registry-token']),
        'ghcr.io/v2/*' => Http::response('', 200),
    ]);

    return demoTarget([
        'source' => 'local', 'local_path' => $repo, 'github_owner' => 'KielD-01', 'github_repo' => 'site',
        'dockerized' => true, 'stack_name' => 'example', 'compose_files' => ['compose.prod.yaml'],
        'app_services' => ['app-blue', 'app-green', 'worker'], 'migrate_service' => 'worker', 'shared_paths' => [],
        'activation' => 'script', 'activation_script' => 'deploy.sh',
        'delivery_strategies' => [['key' => 'ghcr_image', 'enabled' => true]],
        'detection' => [
            'workflows' => [['file' => 'deploy.yml', 'triggers' => ['push' => ['main']], 'images' => [['image' => 'ghcr.io/{owner}/{repo}', 'tag' => '{sha}', 'count' => 1]]]],
            'compose' => [[
                'file' => 'compose.prod.yaml', 'name' => 'example', 'app_services' => ['app-blue', 'app-green', 'worker'],
                'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/kield-01/site']],
            ]],
        ],
    ]);
}

function blueGreenRun(Target $target, DeploymentKind $kind = DeploymentKind::Deploy, array $options = []): Deployment
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => $kind, 'options' => $options]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

it('hands the project’s own deploy script CI’s image tag and the registry login', function () {
    $credential = Credential::factory()->create();

    $deployment = blueGreenRun(blueGreenTarget());
    $scripts = collect($this->ssh->calls)->pluck('script');
    $login = $scripts->first(fn ($script) => $script->name === 'deploy-registry-login');
    $run = $scripts->first(fn ($script) => $script->name === 'deploy-run-script');
    $forget = $scripts->first(fn ($script) => $script->name === 'deploy-forget-login');
    $steps = $deployment->steps()->get()->mapWithKeys(fn ($step) => [$step->key => $step->status->value])->all();

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and($steps)->toMatchArray(['pins' => 'succeeded', 'login' => 'succeeded', 'pull' => 'skipped', 'build' => 'skipped', 'migrate' => 'skipped', 'activate' => 'succeeded', 'recreate' => 'skipped'])
        // The script finds IMAGE_TAG in the pins next to it, and pulls with this deploy's login...
        ->and($deployment->release->image_pins)->toBe(['IMAGE_TAG' => $deployment->release->commit_sha])
        ->and(scriptVar($run, 'DIR'))->toBe($deployment->release->path)
        ->and(scriptVar($run, 'DOCKER_CONFIG_DIR'))->toBe(scriptVar($login, 'DIR'))
        // ...which the clean-up removes afterwards.
        ->and(scriptVar($forget, 'DIR'))->toBe(scriptVar($login, 'DIR'))
        ->and($deployment->logLines()->pluck('line')->implode("\n"))->not->toContain($credential->token);
});

it('logs in again when a rollback runs the old release’s script', function () {
    Credential::factory()->create();
    $target = blueGreenTarget();
    $first = blueGreenRun($target)->release;
    blueGreenRun($target->refresh());
    $before = count($this->ssh->calls);

    $rollback = blueGreenRun($target->refresh(), DeploymentKind::Rollback, ['release_id' => $first->id]);
    $scripts = collect(array_slice($this->ssh->calls, $before))->pluck('script');
    $login = $scripts->first(fn ($script) => $script->name === 'deploy-registry-login');
    $run = $scripts->first(fn ($script) => $script->name === 'deploy-run-script');

    expect($rollback->status)->toBe(DeploymentStatus::Succeeded, (string) $rollback->outcome)
        ->and($login)->not->toBeNull()
        ->and(scriptVar($run, 'DIR'))->toBe($first->path)
        ->and(scriptVar($run, 'DOCKER_CONFIG_DIR'))->toBe(scriptVar($login, 'DIR'));
});

it('runs the old release’s script again when a deploy switches back on its own', function () {
    Credential::factory()->create();
    $target = blueGreenTarget();
    $first = blueGreenRun($target)->release;

    // The next release goes live through its script, then fails its health check.
    $target->project->update(['health_url' => 'https://example.dev/up', 'auto_rollback' => true]);
    $this->ssh->on('box', 'deploy-health', new SshResult(30, "✗ gave HTTP 502\n::deployme::last=HTTP 502\n", '', 1.0));
    $before = count($this->ssh->calls);
    $failed = blueGreenRun($target->refresh());
    $runs = collect(array_slice($this->ssh->calls, $before))->pluck('script')->filter(fn ($script) => $script->name === 'deploy-run-script')->values();

    // current switching back moves no container the script started: the old
    // release's script brings its stack back.
    expect($failed->status)->toBe(DeploymentStatus::RolledBack, (string) $failed->outcome)
        ->and($runs)->toHaveCount(2)
        ->and(scriptVar($runs[0], 'DIR'))->toBe($failed->release->path)
        ->and(scriptVar($runs[1], 'DIR'))->toBe($first->path)
        ->and(scriptVar($runs[1], 'RELEASE'))->toBe($first->name);
});

it('logs in and pulls the old release’s images when a Compose project rolls back', function () {
    Credential::factory()->create();
    $target = blueGreenTarget();
    $target->project->update(['activation' => 'builtin', 'activation_script' => null]);

    $first = blueGreenRun($target->refresh())->release;
    blueGreenRun($target->refresh());
    $before = count($this->ssh->calls);

    $rollback = blueGreenRun($target->refresh(), DeploymentKind::Rollback, ['release_id' => $first->id]);
    $scripts = collect(array_slice($this->ssh->calls, $before))->pluck('script');
    $login = $scripts->first(fn ($script) => $script->name === 'deploy-registry-login');
    $pull = $scripts->first(fn ($script) => $script->name === 'deploy-compose' && scriptVar($script, 'ACTION') === 'pull');

    // The release says which strategy shipped it, so the rollback can fetch
    // its images again instead of hoping they're still on the server.
    expect($rollback->status)->toBe(DeploymentStatus::Succeeded, (string) $rollback->outcome)
        ->and($rollback->steps()->get()->mapWithKeys(fn ($step) => [$step->key => $step->status->value])->all())
        ->toMatchArray(['pick' => 'succeeded', 'login' => 'succeeded', 'restore_pins' => 'skipped', 'pull' => 'succeeded', 'activate' => 'succeeded'])
        ->and(scriptVar($pull, 'DIR'))->toBe($first->path)
        ->and(scriptVar($pull, 'DOCKER_CONFIG_DIR'))->toBe(scriptVar($login, 'DIR'));
});
