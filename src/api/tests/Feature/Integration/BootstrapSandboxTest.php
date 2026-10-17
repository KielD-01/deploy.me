<?php

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Server;
use App\Models\Target;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;
use Illuminate\Support\Facades\Event;

// Real runs of the deploy scripts against the local sandbox (`make sandbox`),
// each in its own folder under /opt/srv that's removed afterwards. Skipped
// when the sandbox isn't running.
beforeEach(function () {
    if (gethostbyname('deployme-sshd-target') === 'deployme-sshd-target' || ! is_readable(storage_path('app/sandbox-ssh/ssh_config'))) {
        $this->markTestSkipped('The sandbox isn’t running: `make sandbox`.');
    }

    config([
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-it-'.bin2hex(random_bytes(4)),
        'deployme.ssh.user_known_hosts' => '/nonexistent',
    ]);

    $this->app->singleton(SshConfigCompiler::class, fn () => SshConfigCompiler::fromConfig());
    $this->app->singleton(KnownHosts::class);
    $this->app->singleton(SshClient::class, ProcessSshClient::class);
    app(KnownHosts::class)->trust(app(KnownHosts::class)->scan('sandbox'), null);

    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
    $this->slug = 'it-'.bin2hex(random_bytes(3));
});

afterEach(function () {
    if (isset($this->slug)) {
        app(SshClient::class)->run('sandbox', RemoteScript::inline('cleanup', 'rm -rf -- "$DIR"', ['DIR' => "/opt/srv/{$this->slug}"]));
    }
});

function sandboxTarget(string $slug, array $project = []): Target
{
    $server = Server::factory()->create(['ssh_alias' => 'sandbox', 'default_deploy_path' => '/opt/srv']);
    $project = Project::factory()->create(['slug' => $slug, 'name' => $slug, ...$project]);

    return Target::factory()->create(['server_id' => $server->id, 'project_id' => $project->id, 'base_dir' => '/opt/srv']);
}

it('prepares a real folder: releases, shared env files, state, and no lock left behind', function () {
    $target = sandboxTarget($this->slug, ['dockerized' => true, 'stack_name' => $this->slug]);
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("APP_NAME=Demo\nAPP_KEY=base64:it-secret-value\n")]);
    $deployment = Deployment::factory()->create(['target_id' => $target->id]);

    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome);

    $check = app(SshClient::class)->run('sandbox', RemoteScript::inline('check', <<<'SH'
        cd -- "$DIR"
        ls -A | sort | tr '\n' ' '; echo
        stat -c '%a' shared/.env
        cat shared/.env
        cat .deploy/state.json
        if [ -d .deploy/lock ]; then echo locked; else echo unlocked; fi
        SH, ['DIR' => "/opt/srv/{$this->slug}"]))->throw()->stdout;

    expect($check)->toContain('.deploy releases shared')
        ->toContain("600\nAPP_NAME=Demo\nAPP_KEY=base64:it-secret-value\n")
        ->toContain('"project": "'.$this->slug.'"')
        ->toContain('unlocked');

    expect($deployment->logLines()->pluck('line')->implode("\n"))
        ->toContain('✓ Docker 28.3.2 · Compose 2.39.1')
        ->not->toContain('it-secret-value');
})->group('ssh-integration');

it('keeps the files of a deployment it didn’t make, and says so', function () {
    $target = sandboxTarget($this->slug);
    app(SshClient::class)->run('sandbox', RemoteScript::inline(
        'existing',
        'mkdir -p -- "$DIR" && printf "services: {}\n" > "$DIR/compose.prod.yaml" && printf "#!/bin/sh\n" > "$DIR/deploy.sh"',
        ['DIR' => "/opt/srv/{$this->slug}"],
    ))->throw();

    $deployment = Deployment::factory()->create(['target_id' => $target->id]);
    app(PipelineRunner::class)->run($deployment);

    $listing = app(SshClient::class)->run('sandbox', RemoteScript::inline('ls', 'ls -A -- "$DIR" | sort | tr "\n" " "', ['DIR' => "/opt/srv/{$this->slug}"]))->throw()->stdout;

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->outcome)->toContain('It already held compose.prod.yaml, deploy.sh from an earlier deployment')
        ->and($listing)->toBe('.deploy compose.prod.yaml deploy.sh releases shared ');
})->group('ssh-integration');

it('won’t run while another deploy holds the lock', function () {
    $target = sandboxTarget($this->slug);
    app(SshClient::class)->run('sandbox', RemoteScript::inline(
        'hold',
        'mkdir -p -- "$DIR/.deploy/lock" && printf "999 %s\n" "$(date +%s)" > "$DIR/.deploy/lock/owner"',
        ['DIR' => "/opt/srv/{$this->slug}"],
    ))->throw();

    $deployment = Deployment::factory()->create(['target_id' => $target->id]);
    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toContain('Deploy #999 has held the lock');
})->group('ssh-integration');
