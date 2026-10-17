<?php

use App\Deploy\Pipeline\PipelineRunner;
use App\Deploy\ServerBuild;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;
use App\Models\Project;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

/*
 * A project whose compose file only names its image, the way KielD-01/warehouse does
 * (`image: ${REGISTRY}/warehouse-api:${IMAGE_TAG}`, built by CI), shipped as files: the
 * server builds that image from a recipe deploy.me keeps, without the project changing.
 */
beforeEach(function () {
    config([
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 0,
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-serverbuild-'.bin2hex(random_bytes(4)),
    ]);

    $this->ssh = fakeDeployScripts(new FakeSshClient);
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
    Process::fake(['*rsync*' => Process::result("Number of files: 4\nNumber of regular files transferred: 4\nTotal file size: 900 bytes\nTotal transferred file size: 900 bytes\n")]);
});

function namedImageTarget(array $project = [])
{
    $repo = tempGitRepo([
        'compose.yaml' => "services:\n  api:\n    image: \${REGISTRY}/demo-api:\${IMAGE_TAG}\n  worker:\n    image: \${REGISTRY}/demo-api:\${IMAGE_TAG}\n",
        'core/prod/Dockerfile' => "FROM php:8.5-cli\n",
    ]);

    return demoTarget([
        'source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null,
        'dockerized' => true, 'stack_name' => 'demo', 'compose_files' => ['compose.yaml'],
        'app_services' => ['api', 'worker'], 'migrate_service' => null, 'shared_paths' => ['storage'],
        'delivery_strategies' => [['key' => 'push_archive', 'enabled' => true]],
        'detection' => ['compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => '${registry}/demo-api']]]]],
        'server_builds' => [[
            'service' => 'api', 'dockerfile' => 'core/prod/Dockerfile', 'context' => '.', 'target' => 'runtime',
            'args' => ['APP_RELEASE' => '{sha}', 'SHORT' => '{sha7}'],
        ]],
        ...$project,
    ]);
}

it('builds an image the compose file only names, from the recipe deploy.me keeps', function () {
    $deployment = Deployment::factory()->create(['target_id' => namedImageTarget()->id, 'kind' => DeploymentKind::Deploy, 'options' => []]);
    app(PipelineRunner::class)->run($deployment);
    $deployment->refresh();
    $release = $deployment->release;
    $sha = (string) $release->commit_sha;
    $sha7 = (string) $release->shortSha();

    $override = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-write-file'
        && scriptVar($call['script'], 'FILE') === "{$release->path}/".ServerBuild::FILE)['script'] ?? null;
    $build = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-compose'
        && scriptVar($call['script'], 'ACTION') === 'build')['script'];
    $recreate = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-compose'
        && scriptVar($call['script'], 'ACTION') === 'recreate')['script'];

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and($release->image_pins)->toBe(['IMAGE_TAG' => $sha7])
        ->and($override)->toBeInstanceOf(RemoteScript::class)
        ->and(scriptVar($override, 'CONTENT'))->toContain("  \"api\":\n    build:\n      context: \".\"\n      dockerfile: \"core/prod/Dockerfile\"\n      target: \"runtime\"\n")
        ->and(scriptVar($override, 'CONTENT'))->toContain("\"APP_RELEASE\": \"{$sha}\"")
        ->and(scriptVar($override, 'CONTENT'))->toContain("\"SHORT\": \"{$sha7}\"")
        // The build reads the override; bringing the stack up doesn't, so nothing else tries to build.
        ->and(explode("\n", (string) scriptVar($build, 'FILES')))->toBe(['compose.yaml', ServerBuild::FILE])
        ->and(explode("\n", (string) scriptVar($recreate, 'FILES')))->toBe(['compose.yaml'])
        ->and($deployment->steps()->where('key', 'build')->value('detail'))->toBe('docker compose build · api');

    $ignore = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-build-ignore')['script'];
    expect(scriptVar($ignore, 'DOCKERFILE'))->toBe('core/prod/Dockerfile')
        ->and(scriptVar($ignore, 'EXCLUDE'))->toBe('storage');
});

it('names a shared path inside a narrower build context relative to it, and leaves the rest out', function () {
    $project = new Project(['shared_paths' => ['src/api/storage', 'uploads']]);

    expect(ServerBuild::excluded($project, 'src/api'))->toBe(['storage'])
        ->and(ServerBuild::excluded($project, '.'))->toBe(['src/api/storage', 'uploads']);
});

it('writes no override for a compose file that builds its own images', function () {
    $deployment = Deployment::factory()->create(['target_id' => namedImageTarget(['server_builds' => null])->id, 'kind' => DeploymentKind::Deploy, 'options' => []]);
    app(PipelineRunner::class)->run($deployment);

    $writes = collect($this->ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-write-file')
        ->map(fn (array $call) => basename((string) scriptVar($call['script'], 'FILE')))->values()->all();

    expect($writes)->not->toContain(ServerBuild::FILE);
});

it('keeps only usable build rows', function () {
    $project = new Project(['server_builds' => [
        ['service' => 'api', 'dockerfile' => 'Dockerfile', 'args' => ['OK' => 'x', 'bad name' => 'y', 'N' => 3]],
        ['service' => '', 'dockerfile' => 'Dockerfile'],
        ['service' => 'web'],
        'nonsense',
    ]]);

    expect($project->serverBuilds())->toBe([
        ['service' => 'api', 'context' => '.', 'dockerfile' => 'Dockerfile', 'target' => null, 'args' => ['OK' => 'x', 'N' => '3']],
    ]);
});
