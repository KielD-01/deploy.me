<?php

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\StepStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;
use App\Models\Target;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config([
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 0,
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-bluegreen-'.bin2hex(random_bytes(4)),
    ]);

    $this->ssh = fakeDeployScripts(new FakeSshClient);
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
    Process::fake(['*rsync*' => Process::result("Number of files: 2\nNumber of regular files transferred: 2\nTotal file size: 200 bytes\nTotal transferred file size: 200 bytes\n")]);
});

/**
 * A Docker project whose api sits behind the edge with a health check of its
 * own, and whose worker has neither.
 */
function rollingTarget(array $project = []): Target
{
    $repo = tempGitRepo(['compose.yaml' => "services:\n  api:\n    build: .\n  worker:\n    build: .\n", 'Dockerfile' => "FROM php:8.5-cli\n"]);

    return demoTarget([
        'source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null,
        'dockerized' => true, 'blue_green' => true, 'stack_name' => 'demo', 'compose_files' => ['compose.yaml'],
        'app_services' => ['api', 'worker'], 'migrate_service' => null, 'shared_paths' => [],
        'delivery_strategies' => [['key' => 'push_archive', 'enabled' => true]],
        'detection' => ['compose' => [[
            'file' => 'compose.yaml', 'services' => ['api', 'worker'], 'app_services' => ['api', 'worker'],
            'edge_services' => ['api'], 'healthchecked' => ['api'],
        ]]],
        ...$project,
    ]);
}

function rollingRun(Target $target): Deployment
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

it('starts the edge-facing service beside the old one, recreates the rest, and alternates the colour', function () {
    $target = rollingTarget();

    $first = rollingRun($target);
    $second = rollingRun($target);

    $scripts = collect($this->ssh->calls)->pluck('script');
    $rolled = $scripts->filter(fn (RemoteScript $script) => $script->name === 'deploy-compose-roll')->values();
    $recreated = $scripts->filter(fn (RemoteScript $script) => $script->name === 'deploy-compose' && scriptVar($script, 'ACTION') === 'recreate')->values();

    expect($second->status)->toBe(DeploymentStatus::Succeeded, (string) $second->outcome)
        ->and($rolled)->toHaveCount(2)
        // The edge-facing service is rolled; the worker is recreated as before.
        ->and(scriptVar($rolled[1], 'SERVICES'))->toBe('api')
        ->and(scriptVar($rolled[1], 'COLOR'))->toBe('green')
        ->and(scriptVar($recreated[1], 'SERVICES'))->toBe('worker')
        ->and($first->release->color)->toBe('blue')
        ->and($second->release->color)->toBe('green')
        ->and($second->steps()->where('key', 'roll')->value('detail'))->toBe('green · 2 started, 2 retired')
        ->and($second->steps()->where('key', 'recreate')->value('detail'))->toBe('worker');
});

it('keeps the old containers serving when the new colour never turns healthy', function () {
    $target = rollingTarget();
    $first = rollingRun($target);

    fakeDeployScripts($this->ssh, ['deploy-compose-roll' => new SshResult(6, "✗ After 180 s: demo-api-3 (starting)\n! Removing the new containers again; the old ones keep serving.\n::deployme::started=0\n", '', 180.0)]);
    $failed = rollingRun($target);

    expect($failed->status)->toBe(DeploymentStatus::RolledBack)
        // The script's own line says what was still starting.
        ->and($failed->outcome)->toStartWith('After 180 s: demo-api-3 (starting).')
        ->and($failed->outcome)->toContain('Switched back to release')
        ->and($failed->steps()->where('key', 'roll')->value('status'))->toBe(StepStatus::Failed)
        // Nothing after the roll ran, and current points back at the first release.
        ->and($failed->steps()->where('key', 'recreate')->value('status'))->toBe(StepStatus::Skipped)
        ->and($target->refresh()->current_release_id)->toBe($first->release->id);
});

it('leaves projects that haven’t asked for it alone', function () {
    $target = rollingTarget(['blue_green' => false]);

    $deployment = rollingRun($target);

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and($deployment->steps()->where('key', 'roll')->value('status'))->toBe(StepStatus::Skipped)
        ->and($deployment->steps()->where('key', 'recreate')->value('detail'))->toBe('api, worker')
        ->and($deployment->release->color)->toBeNull();
});
