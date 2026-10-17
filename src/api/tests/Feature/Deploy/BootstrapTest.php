<?php

use App\Actions\Deployments\CancelDeployment;
use App\Actions\Deployments\DetectStalledDeployments;
use App\Actions\Deployments\StartDeployment;
use App\Deploy\DeploymentBusy;
use App\Deploy\Logging\StepLogger;
use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\ProjectEnvFile;
use App\Models\Release;
use App\Platform\Ssh\Exceptions\HostUnreachable;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
});

function stepStatuses(Deployment $deployment): array
{
    return $deployment->steps()->get()->mapWithKeys(fn ($step) => [$step->key => $step->status->value])->all();
}

it('prepares a target: folders, env files and state, then gives the lock back', function () {
    $target = demoTarget();
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("APP_NAME=Demo\nAPP_KEY=base64:supersecretvalue\n")]);
    fakeDeployScripts($this->ssh, ['deploy-write-file' => FakeSshClient::ok("✓ /opt/srv/demo/shared/.env (44 bytes, mode 0600)\nthe server echoed base64:supersecretvalue\n")]);

    $deployment = Deployment::factory()->create(['target_id' => $target->id]);
    app(PipelineRunner::class)->run($deployment);
    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->outcome)->toBe('/opt/srv/demo is ready for deploys.')
        ->and(stepStatuses($deployment))->toBe([
            'preflight' => 'succeeded', 'lease' => 'succeeded', 'folders' => 'succeeded',
            'env' => 'succeeded', 'state' => 'succeeded', 'unlock' => 'succeeded',
        ])
        ->and($deployment->steps()->where('key', 'preflight')->value('detail'))->toBe('97.7 GB free')
        ->and($deployment->started_at)->not->toBeNull()
        ->and($target->refresh()->bootstrapped_at)->not->toBeNull();

    $log = $deployment->logLines()->pluck('line')->implode("\n");
    expect($log)->toContain('▸ Pre-flight checks')
        ->toContain('the server echoed ••••••')
        ->not->toContain('supersecretvalue')
        ->not->toContain('::deployme::');

    // The env file travels inside the script over stdin, into shared/.
    $write = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-write-file');
    expect($write['script']->render())->toContain(base64_encode('/opt/srv/demo/shared/.env'))->not->toContain('supersecretvalue');

    Event::assertDispatched(DeploymentLogged::class);
    Event::assertDispatched(DeploymentUpdated::class, fn (DeploymentUpdated $event) => $event->status === 'succeeded');
});

it('says so when the folder already holds a deployment it didn’t make', function () {
    fakeDeployScripts($this->ssh, ['deploy-preflight' => FakeSshClient::ok(
        "! /opt/srv/demo already holds 7 item(s) deploy.me didn't put there; they're left as they are\n"
        ."::deployme::foreign=.env|compose.prod.yaml|deploy.sh|docker|web|\n::deployme::foreign_count=7\n::deployme::free=104857600000\n::deployme::ok=1\n"
    )]);
    $deployment = Deployment::factory()->create(['target_id' => demoTarget()->id]);

    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->outcome)->toBe('/opt/srv/demo is ready for deploys. It already held .env, compose.prod.yaml, deploy.sh, docker, web and 2 more from an earlier deployment; those were left as they are. Convert the folder to releases before the first deploy: it’s on the Targets tab.')
        ->and($deployment->target->refresh()->needsConversion())->toBeTrue()
        ->and($deployment->steps()->where('key', 'preflight')->value('detail'))->toBe('97.7 GB free · existing files kept');
});

it('puts env files in the project folder when it deploys in place', function () {
    $target = demoTarget(['versionable' => false]);
    $target->project->envFiles()->create(['path' => 'src/api/.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("APP_NAME=Demo\n")]);
    fakeDeployScripts($this->ssh);

    app(PipelineRunner::class)->run(Deployment::factory()->create(['target_id' => $target->id]));

    $write = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-write-file');
    expect($write['script']->render())->toContain(base64_encode('/opt/srv/demo/src/api/.env'));
});

it('skips uploading env files when the project has none', function () {
    fakeDeployScripts($this->ssh);
    $deployment = Deployment::factory()->create(['target_id' => demoTarget()->id]);

    app(PipelineRunner::class)->run($deployment);

    expect(stepStatuses($deployment))->toMatchArray(['env' => 'skipped', 'state' => 'succeeded']);
});

it('stops at a lock another deploy holds, and leaves that lock alone', function () {
    fakeDeployScripts($this->ssh, ['deploy-lease' => new SshResult(20, "✗ Deploy #7 has held the lock for 3 min\n::deployme::holder=7\n::deployme::age=200\n", '', 0.1)]);
    $deployment = Deployment::factory()->create(['target_id' => demoTarget()->id]);

    app(PipelineRunner::class)->run($deployment);
    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toStartWith('Deploy #7 has held the lock on this folder for 3 min.')
        ->and(stepStatuses($deployment))->toMatchArray(['preflight' => 'succeeded', 'lease' => 'failed', 'folders' => 'skipped', 'unlock' => 'skipped'])
        ->and(collect($this->ssh->calls)->pluck('script.name')->all())->not->toContain('deploy-unlock');
});

it('takes over at once the lock a finished run of this target left behind', function () {
    $target = demoTarget();
    $orphan = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Failed]);
    fakeDeployScripts($this->ssh, ['deploy-lease' => fn (string $alias, RemoteScript $script) => scriptVar($script, 'TAKEOVER') === (string) $orphan->id
        ? FakeSshClient::ok("! Took over the lock deploy #{$orphan->id} left behind\n::deployme::lease=taken_over\n::deployme::previous={$orphan->id}\n")
        : new SshResult(20, "✗ Deploy #{$orphan->id} has held the lock for 3 min\n::deployme::holder={$orphan->id}\n::deployme::age=200\n", '', 0.1)]);
    $deployment = Deployment::factory()->create(['target_id' => $target->id]);

    app(PipelineRunner::class)->run($deployment);
    $leases = collect($this->ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-lease')->values();

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->steps()->where('key', 'lease')->value('detail'))->toBe("took over the lock #{$orphan->id} left behind (it had failed)")
        ->and($leases)->toHaveCount(2)
        ->and(scriptVar($leases[0]['script'], 'TAKEOVER'))->toBe('')
        ->and(scriptVar($leases[1]['script'], 'TAKEOVER'))->toBe((string) $orphan->id);
});

it('waits for a lock it can’t tell was left behind', function (string $holder) {
    $id = match ($holder) {
        'another target’s finished run' => Deployment::factory()->create(['status' => DeploymentStatus::Failed])->id,
        'a run still going elsewhere' => Deployment::factory()->create(['status' => DeploymentStatus::Running])->id,
        default => 987654321,
    };
    fakeDeployScripts($this->ssh, ['deploy-lease' => new SshResult(20, "✗ Deploy #{$id} has held the lock for 3 min\n::deployme::holder={$id}\n::deployme::age=200\n", '', 0.1)]);
    $deployment = Deployment::factory()->create(['target_id' => demoTarget()->id]);

    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toStartWith("Deploy #{$id} has held the lock on this folder for 3 min.")
        ->and(collect($this->ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-lease'))->toHaveCount(1);
})->with(['another target’s finished run', 'a run still going elsewhere', 'an id deploy.me never gave out']);

it('breaks a lock by hand, whoever holds it, in a run of its own', function () {
    fakeDeployScripts($this->ssh, ['deploy-unlock' => FakeSshClient::ok("✓ Broke the lock deploy #41 held on /opt/srv/demo for 130 min\n::deployme::broken=1\n::deployme::previous=41\n")]);
    $deployment = Deployment::factory()->create(['target_id' => demoTarget()->id, 'kind' => DeploymentKind::Unlock]);

    app(PipelineRunner::class)->run($deployment);
    $unlock = $this->ssh->calls[0]['script'];

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->outcome)->toBe('Broke the lock deploy #41 held on /opt/srv/demo.')
        ->and(stepStatuses($deployment))->toBe(['break_lock' => 'succeeded'])
        ->and($deployment->steps()->where('key', 'break_lock')->value('detail'))->toBe('deploy #41 held it')
        ->and(collect($this->ssh->calls)->pluck('script.name')->all())->toBe(['deploy-unlock'])
        ->and(scriptVar($unlock, 'FORCE'))->toBe('1')
        ->and(scriptVar($unlock, 'TARGET'))->toBe('/opt/srv/demo');
});

it('fails with the server’s own reason', function () {
    fakeDeployScripts($this->ssh, ['deploy-preflight' => new SshResult(11, "✗ /opt/srv isn't writable for deploy\n", '', 0.1)]);
    $deployment = Deployment::factory()->create(['target_id' => demoTarget()->id]);

    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->outcome)->toBe("/opt/srv isn't writable for deploy")
        ->and($deployment->steps()->where('key', 'preflight')->value('exit_code'))->toBe(11);
});

it('says so when the server can’t be reached', function () {
    fakeDeployScripts($this->ssh, ['deploy-preflight' => new HostUnreachable('ssh: connect to host 203.0.113.10 port 22: Connection timed out')]);
    $deployment = Deployment::factory()->create(['target_id' => demoTarget()->id]);

    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toContain('Couldn’t reach the server');
});

it('refuses to prepare a folder inside the shared infrastructure', function () {
    $target = demoTarget();
    $target->update(['path' => '/opt/infra/demo']);
    $deployment = Deployment::factory()->create(['target_id' => $target->id]);

    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->outcome)->toContain('reserved for the shared infrastructure');
    $this->ssh->assertNothingRan();
});

it('stops when cancelled, and still gives the lock back', function () {
    $deployment = Deployment::factory()->create(['target_id' => demoTarget()->id]);
    fakeDeployScripts($this->ssh, ['deploy-bootstrap' => function () use ($deployment) {
        app(CancelDeployment::class)($deployment);

        return FakeSshClient::ok("✓ ready\n");
    }]);

    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and(stepStatuses($deployment))->toMatchArray(['folders' => 'succeeded', 'state' => 'skipped', 'unlock' => 'succeeded']);
});

it('queues one run per target at a time', function () {
    Queue::fake();
    $target = demoTarget();

    $first = app(StartDeployment::class)($target, DeploymentKind::Bootstrap);

    Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deploymentId === $first->id && $job->connection === 'redis-deploys');
    expect(fn () => app(StartDeployment::class)($target, DeploymentKind::Bootstrap))->toThrow(DeploymentBusy::class);

    app(CancelDeployment::class)($first);
    expect(app(StartDeployment::class)($target, DeploymentKind::Bootstrap)->id)->toBeGreaterThan($first->id);
});

it('cancels a queued run on the spot, and a running one at its next check', function () {
    $queued = Deployment::factory()->create();
    $running = Deployment::factory()->create(['status' => DeploymentStatus::Running]);

    app(CancelDeployment::class)($queued);
    app(CancelDeployment::class)($running);

    expect($queued->refresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($running->refresh()->status)->toBe(DeploymentStatus::Running)
        ->and($running->cancel_requested_at)->not->toBeNull();
});

it('leaves a run alone that was cancelled while it waited in the queue', function () {
    $deployment = Deployment::factory()->create(['status' => DeploymentStatus::Cancelled]);

    (new RunDeployment($deployment->id))->handle(app(PipelineRunner::class));

    $this->ssh->assertNothingRan();
});

it('marks runs whose worker went quiet as stalled, and fails the release they were building', function () {
    $quiet = Deployment::factory()->create(['kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Running, 'heartbeat_at' => now()->subMinutes(3)]);
    $building = Release::factory()->create(['target_id' => $quiet->target_id, 'deployment_id' => $quiet->id, 'status' => ReleaseStatus::Building]);
    $quiet->update(['release_id' => $building->id]);
    Deployment::factory()->create(['status' => DeploymentStatus::Running, 'heartbeat_at' => now()]);

    expect(app(DetectStalledDeployments::class)())->toBe(1)
        ->and($quiet->refresh()->status)->toBe(DeploymentStatus::Stalled)
        ->and($building->refresh()->status)->toBe(ReleaseStatus::Failed);
});

it('frees a target whose run waits for CI past the hour with nothing coming back for it', function () {
    // Its re-check job was lost (the queue's store was flushed, say): nothing will pick it up.
    $lost = Deployment::factory()->create(['status' => DeploymentStatus::WaitingCi, 'heartbeat_at' => now()->subMinutes(75)]);
    $lost->forceFill(['created_at' => now()->subMinutes(80)])->save();
    // Within its hour, and one whose re-check sits behind a long deploy: both left alone.
    Deployment::factory()->create(['status' => DeploymentStatus::WaitingCi, 'heartbeat_at' => now()->subMinutes(30)]);
    $behind = Deployment::factory()->create(['status' => DeploymentStatus::WaitingCi, 'heartbeat_at' => now()->subMinutes(2)]);
    $behind->forceFill(['created_at' => now()->subMinutes(80)])->save();

    expect(app(DetectStalledDeployments::class)())->toBe(1)
        ->and($lost->refresh()->status)->toBe(DeploymentStatus::Stalled)
        ->and($lost->outcome)->toContain('CI')
        ->and($behind->refresh()->status)->toBe(DeploymentStatus::WaitingCi);
});

it('fails the release a run was building when its worker dies', function () {
    $run = Deployment::factory()->create(['kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Running]);
    $building = Release::factory()->create(['target_id' => $run->target_id, 'deployment_id' => $run->id, 'status' => ReleaseStatus::Building]);
    $run->update(['release_id' => $building->id]);

    (new RunDeployment($run->id))->failed(new RuntimeException('Worker killed'));

    expect($run->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($run->outcome)->toBe('The deploy worker stopped: Worker killed.')
        ->and($building->refresh()->status)->toBe(ReleaseStatus::Failed);
});

it('cleans terminal codes and masks tokens in the log', function () {
    $deployment = Deployment::factory()->create();
    $log = new StepLogger($deployment);

    $log->line("\e[32mOK\e[0m pulled with ghp_abcdefghijklmnopqrstuvwxyz0123");
    $log->line('fetched https://user:pass@example.com/repo.git');
    $log->flush();

    expect($deployment->logLines()->pluck('line')->all())->toBe(['OK pulled with ••••••', 'fetched https://example.com/repo.git'])
        ->and($deployment->refresh()->log_lines)->toBe(2);
});
