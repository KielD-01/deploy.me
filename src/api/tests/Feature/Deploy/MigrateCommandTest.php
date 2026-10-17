<?php

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config([
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 0,
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-migrate-'.bin2hex(random_bytes(4)),
    ]);

    $this->ssh = fakeDeployScripts(new FakeSshClient);
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
    Process::fake(['*rsync*' => Process::result("Number of files: 2\nNumber of regular files transferred: 2\nTotal file size: 200 bytes\nTotal transferred file size: 200 bytes\n")]);
});

it('runs a migrate service that migrates by itself with its own command, and migrates in any other', function (string $service, string $command) {
    $repo = tempGitRepo(['docker-compose.prod.yml' => "services:\n  shop-api:\n    build: .\n", 'Dockerfile' => "FROM php:8.5-cli\n"]);
    $target = demoTarget([
        'source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null,
        'dockerized' => true, 'stack_name' => 'shop', 'compose_files' => ['docker-compose.prod.yml'],
        'app_services' => ['shop-api'], 'migrate_service' => $service, 'shared_paths' => [],
        'delivery_strategies' => [['key' => 'push_archive', 'enabled' => true]],
        'detection' => ['compose' => [[
            'file' => 'docker-compose.prod.yml', 'services' => ['shop-api', 'shop-migrate'], 'app_services' => ['shop-api'],
            'commands' => ['shop-migrate' => 'php artisan migrate --force --database=pgsql_direct'],
        ]]],
    ]);

    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy]);
    app(PipelineRunner::class)->run($deployment);
    $run = collect($this->ssh->calls)->pluck('script')->first(fn ($script) => $script->name === 'deploy-compose' && scriptVar($script, 'ACTION') === 'run');

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and(scriptVar($run, 'SERVICES'))->toBe($service)
        ->and(scriptVar($run, 'COMMAND'))->toBe($command);
})->with([
    'a dedicated one, with its own command' => ['shop-migrate', ''],
    'the app, with php artisan migrate' => ['shop-api', 'php artisan migrate --force'],
]);
