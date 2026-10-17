<?php

use App\Actions\Maintenance\PruneOldData;
use App\Actions\Targets\CheckTargetHealth;
use App\Actions\Targets\DispatchDueHealthChecks;
use App\Deploy\Logging\LogArchive;
use App\Deploy\Logging\StepLogger;
use App\Enums\CiState;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\SyncRunStatus;
use App\Enums\TargetHealth;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Events\TargetHealthChanged;
use App\Jobs\CheckHealth;
use App\Livewire\Deployments\Show as RunPage;
use App\Livewire\Settings\Index as SettingsPage;
use App\Models\Deployment;
use App\Models\FolderMapping;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Models\SyncRun;
use App\Models\Target;
use App\Models\TargetHealthCheck;
use App\Models\User;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use App\Support\Settings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class, TargetHealthChanged::class]);
});

/**
 * A target with a live release, on the fake server "box".
 */
function liveTarget(array $project = []): Target
{
    $target = demoTarget($project);
    $release = Release::factory()->create(['target_id' => $target->id, 'commit_sha' => str_repeat('a', 40)]);
    $target->update(['current_release_id' => $release->id]);

    return $target->refresh();
}

it('checks a deployed target’s health from its server, and remembers since when it fails', function () {
    $target = liveTarget(['health_url' => 'https://demo.test/up']);
    $this->ssh->on('box', 'deploy-health', FakeSshClient::ok("✓ https://demo.test/up answered 200 after 0 s\n::deployme::code=200\n::deployme::after=0\n"));

    expect(app(CheckTargetHealth::class)($target))->toBe(TargetHealth::Healthy)
        ->and($target->refresh()->health_detail)->toBe('HTTP 200')
        ->and($target->unhealthy_since)->toBeNull();

    $this->ssh->on('box', 'deploy-health', new SshResult(30, "✗ https://demo.test/up gave HTTP 502 for 5 s\n::deployme::last=HTTP 502\n", '', 5.0));
    app(CheckTargetHealth::class)($target);
    $since = $target->refresh()->unhealthy_since;

    expect($target->health)->toBe(TargetHealth::Unhealthy)
        ->and($target->health_detail)->toBe('https://demo.test/up gives HTTP 502')
        ->and($since)->not->toBeNull();

    // Still failing a minute later: failing since the first time.
    $this->travel(1)->minutes();
    app(CheckTargetHealth::class)($target);
    expect($target->refresh()->unhealthy_since->equalTo($since))->toBeTrue();

    // Open pages hear about the two changes, not about every check.
    Event::assertDispatchedTimes(TargetHealthChanged::class, 2);
});

it('alerts when health changes, not when only the failure’s wording does', function () {
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    Settings::setString('telegram_bot_token', '123456:test-token');
    Settings::setString('telegram_chat_id', '4242');
    $target = liveTarget(['health_url' => 'https://demo.test/up']);

    $this->ssh->on('box', 'deploy-health', FakeSshClient::ok("✓ answered 200\n::deployme::code=200\n"));
    app(CheckTargetHealth::class)($target->refresh());
    Http::assertNothingSent();

    foreach (['HTTP 502', 'HTTP 503', 'HTTP 502'] as $answer) {
        $this->ssh->on('box', 'deploy-health', new SshResult(30, "✗ gave {$answer}\n::deployme::last={$answer}\n", '', 5.0));
        app(CheckTargetHealth::class)($target->refresh());
    }

    $this->ssh->on('box', 'deploy-health', FakeSshClient::ok("✓ answered 204\n::deployme::code=204\n"));
    app(CheckTargetHealth::class)($target->refresh());

    // One for going down, one for coming back.
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request['text'], 'stopped answering') && str_contains($request['text'], '502'));
    Http::assertSent(fn ($request) => str_contains($request['text'], 'answering again'));
});

it('checks a Docker project’s app services too', function () {
    $target = liveTarget(['dockerized' => true, 'stack_name' => 'demo', 'compose_files' => ['compose.yaml'], 'app_services' => ['app']]);
    $this->ssh->on('box', 'deploy-compose-health', new SshResult(30, "✗ After 5 s: app (running, unhealthy)\n::deployme::waiting=app (running, unhealthy)\n", '', 5.0));

    expect(app(CheckTargetHealth::class)($target))->toBe(TargetHealth::Unhealthy)
        ->and($target->refresh()->health_detail)->toBe('app (running, unhealthy)');
});

it('queues health checks for deployed targets that are due', function () {
    Queue::fake();
    $due = liveTarget(['health_url' => 'https://demo.test/up']);
    // Checked a moment ago, and nothing deployed: not due.
    Target::factory()->create([
        'project_id' => Project::factory()->create(['health_url' => 'https://other.test/up'])->id,
        'server_id' => Server::factory()->create()->id,
        'current_release_id' => $due->current_release_id,
        'health_checked_at' => now(),
    ]);
    Target::factory()->create(['project_id' => Project::factory()->create(['health_url' => 'https://third.test/up'])->id, 'server_id' => Server::factory()->create()->id]);

    expect(app(DispatchDueHealthChecks::class)())->toBe(1);
    Queue::assertPushed(CheckHealth::class, fn (CheckHealth $job) => $job->targetId === $due->id);

    Settings::set('health_interval', 0);
    expect(app(DispatchDueHealthChecks::class)())->toBe(0);
});

it('keeps a history of a target’s checks, and shows it on the server’s Checks tab', function () {
    $target = liveTarget(['health_url' => 'https://demo.test/up']);
    $this->ssh->on('box', 'deploy-health', FakeSshClient::ok("✓ answered 200\n::deployme::code=200\n"));
    app(CheckTargetHealth::class)($target);

    $this->travel(1)->minutes();
    $this->ssh->on('box', 'deploy-health', new SshResult(30, "✗ https://demo.test/up gave HTTP 502 for 5 s\n::deployme::last=HTTP 502\n", '', 5.0));
    app(CheckTargetHealth::class)($target);

    // Newest first, and the result before the last one is still there.
    expect($target->healthChecks()->get()->pluck('health')->all())->toBe([TargetHealth::Unhealthy, TargetHealth::Healthy])
        ->and($target->healthChecks()->first()->detail)->toBe('https://demo.test/up gives HTTP 502')
        ->and($target->healthChecks()->first()->duration_ms)->toBeGreaterThanOrEqual(0);

    $this->get(route('servers.show', ['server' => $target->server, 'tab' => 'checks']))
        ->assertOk()
        ->assertSee('Recent health checks')
        ->assertSee('kept 7 days')
        ->assertSee('https://demo.test/up gives HTTP 502')
        ->assertSee('HTTP 200');
});

it('says so when a server has no health checks yet', function () {
    $target = liveTarget();

    $this->get(route('servers.show', ['server' => $target->server, 'tab' => 'checks']))
        ->assertOk()
        ->assertSee('No health checks yet');
});

it('drops health checks and probe snapshots past their retention', function () {
    $target = liveTarget();
    $target->healthChecks()->create(['health' => TargetHealth::Healthy, 'detail' => 'HTTP 200', 'checked_at' => now()->subDays(8)]);
    $kept = $target->healthChecks()->create(['health' => TargetHealth::Healthy, 'detail' => 'HTTP 200', 'checked_at' => now()->subDay()]);

    ServerSnapshot::query()->create(['server_id' => $target->server_id, 'captured_at' => now()->subDays(31)]);
    $freshSnapshot = ServerSnapshot::query()->create(['server_id' => $target->server_id, 'captured_at' => now()->subDays(2)]);

    $pruned = app(PruneOldData::class)();

    expect($pruned['health_checks'])->toBe(1)
        ->and($pruned['snapshots'])->toBe(1)
        ->and(TargetHealthCheck::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(ServerSnapshot::query()->pluck('id')->all())->toBe([$freshSnapshot->id]);
});

it('moves old logs into archives and still serves them', function () {
    $run = Deployment::factory()->create(['target_id' => demoTarget()->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Succeeded]);
    $run->forceFill(['finished_at' => now()->subDays(20)])->save();
    $log = new StepLogger($run);
    $log->line('▸ Upload files');
    $log->line('✓ 12 files sent');
    $log->flush();

    try {
        expect(app(PruneOldData::class)()['archived'])->toBe(1)
            ->and($run->logLines()->count())->toBe(0)
            ->and(LogArchive::exists($run->id))->toBeTrue();

        expect($this->get(route('deployments.log', $run))->streamedContent())->toContain('▸ Upload files')->toContain('✓ 12 files sent');

        Livewire::test(RunPage::class, ['deployment' => $run->refresh()])->assertSee('This run’s log was archived.');
    } finally {
        @unlink(LogArchive::path($run->id));
    }
});

it('drops old folder syncs but keeps each mapping’s newest', function () {
    $mapping = FolderMapping::factory()->create();
    $runs = collect(range(1, 3))->map(function () use ($mapping) {
        $run = SyncRun::factory()->create(['folder_mapping_id' => $mapping->id, 'status' => SyncRunStatus::Succeeded]);
        $run->forceFill(['created_at' => now()->subDays(200)])->save();

        return $run;
    });

    expect(app(PruneOldData::class)()['syncs'])->toBe(2)
        ->and(SyncRun::query()->pluck('id')->all())->toBe([$runs->last()->id]);
});

it('saves the schedules', function () {
    Livewire::test(SettingsPage::class)
        ->set('tab', 'schedules')
        ->assertSee(['Health checks', 'CI status', 'Deploy logs', 'Log archives', 'Probe history', 'Health history'])
        ->set('schedules.health_interval', '300')
        ->set('schedules.log_retention_days', '30')
        ->call('saveSchedules')
        ->assertSee('Schedules saved.');

    expect(Settings::int('health_interval'))->toBe(300)
        ->and(Settings::int('log_retention_days'))->toBe(30)
        ->and(Settings::int('ci_poll_interval'))->toBe(300);
});

it('shows runs, health checks and what’s behind on the dashboard', function () {
    $target = liveTarget(['health_url' => 'https://demo.test/up', 'ci_state' => CiState::Passing, 'ci_run' => ['sha' => str_repeat('b', 40)]]);
    $target->update(['health' => TargetHealth::Unhealthy, 'health_checked_at' => now(), 'health_detail' => 'https://demo.test/up gives HTTP 502', 'unhealthy_since' => now()->subMinutes(4)]);
    Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Failed, 'outcome' => 'Migrate failed with exit code 1.']);

    asClassic();

    $this->get('/')
        ->assertOk()
        ->assertSee(['Deployments today', 'Running now', 'Health checks passing', 'Needs attention', 'Recent deployments'])
        ->assertSee('demo health check failing')
        ->assertSee('https://demo.test/up gives HTTP 502')
        ->assertSee('Migrate failed with exit code 1.')
        ->assertSee(['Deployed vs latest', 'aaaaaaa', 'bbbbbbb']);
});
