<?php

use App\Actions\Servers\DispatchDueProbes;
use App\Actions\Servers\RecordProbe;
use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\ServerStatus;
use App\Events\ServerProbed;
use App\Jobs\ProbeServer;
use App\Livewire\Servers\Show;
use App\Models\AuditEntry;
use App\Models\Server;
use App\Models\User;
use App\Platform\Ssh\AuditingSshClient;
use App\Platform\Ssh\Exceptions\HostUnreachable;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([ServerProbed::class]);
    Sleep::fake();
});

/**
 * What probe.sh prints, with the busiest-process lists in it.
 *
 * @param  array<string, mixed>  $top
 */
function probeOutputWithTop(array $top): SshResult
{
    return FakeSshClient::ok("__DEPLOYME_PROBE_BEGIN__\n".json_encode([...json_decode(probeFixture(), true), 'top' => $top])."\n__DEPLOYME_PROBE_END__\n");
}

it('records a snapshot and marks the server online', function () {
    $server = Server::factory()->create(['default_deploy_path' => '/opt/srv']);
    $this->ssh->on($server->ssh_alias, 'ping', FakeSshClient::ok())->on($server->ssh_alias, 'probe', probeOutput());

    ProbeServer::dispatchSync($server->id);
    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Online)
        ->and($server->specs['os'])->toBe('Ubuntu 24.04.3 LTS')
        ->and($server->snapshots()->count())->toBe(1)
        ->and($server->next_probe_at->isFuture())->toBeTrue();

    Event::assertDispatched(ServerProbed::class, fn ($event) => $event->serverId === $server->id && $event->status === 'online');
});

it('keeps the busiest processes with the snapshot, and sends the network sampler along', function () {
    $server = Server::factory()->create();
    $this->ssh->on($server->ssh_alias, 'ping', FakeSshClient::ok())->on($server->ssh_alias, 'probe', probeOutputWithTop([
        'window' => 1.02,
        'cpu' => [
            ['pid' => 812, 'name' => 'frankenphp', 'container' => 'warehouse-api-3', 'cpu' => 38.2],
            ['pid' => 90, 'name' => 'postgres', 'container' => '', 'cpu' => 21],
            'not a row',
        ],
        'mem' => [['pid' => 90, 'name' => 'postgres', 'container' => 'infra-postgres-1', 'rss' => 1288490188]],
        'net' => [],
        'net_note' => 'needs passwordless sudo',
    ]));

    ProbeServer::dispatchSync($server->id);

    $top = $server->snapshots()->sole()->processes;

    // The JSON column gives 21.0 back as 21: the same reading.
    expect($top['cpu'])->toEqual([
        ['pid' => 812, 'name' => 'frankenphp', 'container' => 'warehouse-api-3', 'cpu' => 38.2],
        ['pid' => 90, 'name' => 'postgres', 'container' => null, 'cpu' => 21.0],
    ])
        ->and($top['mem'][0]['rss'])->toBe(1288490188)
        ->and($top['net'])->toBe([])
        ->and($top['net_note'])->toBe('needs passwordless sudo')
        ->and(scriptVar(collect($this->ssh->calls)->firstWhere('script.name', 'probe')['script'], 'NET_SAMPLER'))->toContain('ss -tinHe');
});

it('shows the busiest processes on the server page, and an earlier probe’s when picked', function () {
    $server = Server::factory()->create();
    $lists = fn (array $cpu, array $mem = [], array $net = []) => ['window' => 1.0, 'cpu' => $cpu, 'mem' => $mem, 'net' => $net, 'net_note' => null];
    $earlier = $server->snapshots()->create(['captured_at' => now()->subHour(), 'processes' => $lists([['pid' => 7, 'name' => 'old-cruncher', 'container' => null, 'cpu' => 99.0]])]);
    $server->snapshots()->create(['captured_at' => now(), 'processes' => $lists(
        [['pid' => 812, 'name' => 'frankenphp', 'container' => 'warehouse-api-3', 'cpu' => 38.2]],
        [['pid' => 90, 'name' => 'postgres', 'container' => 'infra-postgres-1', 'rss' => 1288490188], ['pid' => 91, 'name' => 'valkey-server', 'container' => 'infra-valkey-1', 'rss' => 214748364]],
        [['pid' => 5, 'name' => 'caddy', 'container' => 'infra-caddy-1', 'tx' => 4300000.0, 'rx' => 120000.0], ['pid' => 812, 'name' => 'frankenphp', 'container' => 'warehouse-api-3', 'tx' => 1000.0, 'rx' => 3000.0]],
    )]);

    Livewire::test(Show::class, ['server' => $server])
        ->assertSee(['Busiest processes', 'frankenphp', 'warehouse-api-3 · pid 812', '38.2%', 'postgres', Format::bytes(1288490188), 'caddy', Format::bytes(4420000.0).'/s'])
        // What the listed processes use between them: memory, and traffic each way.
        ->assertSee(Format::bytes(1288490188 + 214748364).' total')
        ->assertSee('↓ in '.Format::bytes(120000 + 3000).'/s · ↑ out '.Format::bytes(4300000 + 1000).'/s')
        ->assertDontSee('old-cruncher')
        ->set('processesAt', (string) $earlier->id)
        ->assertSee('old-cruncher')
        ->assertDontSee('frankenphp');
});

it('flags a server whose deploy disk is nearly full', function () {
    $server = Server::factory()->create(['default_deploy_path' => '/srv/apps']);
    $this->ssh->on($server->ssh_alias, 'ping', FakeSshClient::ok())->on($server->ssh_alias, 'probe', probeOutput());

    ProbeServer::dispatchSync($server->id);
    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Degraded)
        ->and($server->status_reason)->toContain('95')->toContain('/srv');
});

it('marks a server unreachable without losing its history, and looks again within a minute', function () {
    $server = Server::factory()->create(['probe_interval' => 900]);
    $this->ssh->on($server->ssh_alias, 'ping', new HostUnreachable('ssh: connect to host 203.0.113.10 port 22: Connection timed out'));

    ProbeServer::dispatchSync($server->id);
    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Offline)
        ->and($server->status_reason)->toContain('Couldn’t reach the server')
        ->and($server->snapshots()->count())->toBe(0)
        ->and($server->next_probe_at->lessThanOrEqualTo(now()->addMinute()))->toBeTrue();

    Sleep::assertSleptTimes(1);
});

it('refuses a probe report over 2 MB, as a failed probe with a reason', function () {
    $server = Server::factory()->create();
    $payload = json_encode([...json_decode(probeFixture(), true), 'padding' => str_repeat('x', 3 * 1024 * 1024)]);
    $this->ssh->on($server->ssh_alias, 'ping', FakeSshClient::ok())
        ->on($server->ssh_alias, 'probe', FakeSshClient::ok("__DEPLOYME_PROBE_BEGIN__\n{$payload}\n__DEPLOYME_PROBE_END__\n"));

    ProbeServer::dispatchSync($server->id);
    $server->refresh();

    expect($server->status)->toBe(ServerStatus::Offline)
        ->and($server->status_reason)->toContain('The probe reported 3.0 MB, more than the 2 MB it may')
        ->and($server->snapshots()->count())->toBe(0);

    // Just under the cap still goes through.
    $ok = json_encode([...json_decode(probeFixture(), true), 'padding' => str_repeat('x', 1024 * 1024)]);
    $this->ssh->on($server->ssh_alias, 'probe', FakeSshClient::ok("__DEPLOYME_PROBE_BEGIN__\n{$ok}\n__DEPLOYME_PROBE_END__\n"));

    ProbeServer::dispatchSync($server->id);

    expect($server->refresh()->status)->toBe(ServerStatus::Online)
        ->and($server->snapshots()->count())->toBe(1);
});

it('tries once more before calling a server offline', function () {
    $server = Server::factory()->create();
    $attempts = 0;
    $this->ssh
        ->on($server->ssh_alias, 'ping', function () use (&$attempts) {
            return ++$attempts === 1 ? throw new HostUnreachable('ssh: connect to host 203.0.113.10 port 22: Connection timed out') : FakeSshClient::ok();
        })
        ->on($server->ssh_alias, 'probe', probeOutput());

    ProbeServer::dispatchSync($server->id);

    expect($attempts)->toBe(2)
        ->and($server->refresh()->status)->toBe(ServerStatus::Online)
        ->and($server->snapshots()->count())->toBe(1);
});

it('reports a failed Probe now straight away', function () {
    $server = Server::factory()->create();
    $this->ssh->on($server->ssh_alias, 'ping', new HostUnreachable('ssh: connect to host 203.0.113.10 port 22: Connection timed out'));

    app(RecordProbe::class)($server);

    expect($server->refresh()->status)->toBe(ServerStatus::Offline);
    Sleep::assertNeverSlept();
});

it('queues probes only for servers that are due', function () {
    Queue::fake();
    $due = Server::factory()->create(['next_probe_at' => now()->subMinute()]);
    Server::factory()->create(['next_probe_at' => now()->addMinutes(5)]);
    Server::factory()->create(['next_probe_at' => null, 'archived_at' => now()]);

    expect(app(DispatchDueProbes::class)())->toBe(1);

    Queue::assertPushed(ProbeServer::class, 1);
    Queue::assertPushed(ProbeServer::class, fn ($job) => $job->serverId === $due->id && $job->connection === 'redis-probes');
});

it('starts scheduled probes in the middle of the minute, clear of the cron jobs that start on it', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-15 17:10:02'));
    Server::factory()->create(['next_probe_at' => now()->subMinute()]);

    app(DispatchDueProbes::class)();

    Queue::assertPushed(ProbeServer::class, fn ($job) => $job->delay->equalTo(Carbon::parse('2026-10-15 17:10:30')));
});

it('counts the next probe from the minute that starts it, so the interval doesn’t stretch', function () {
    $this->travelTo(Carbon::parse('2026-10-15 17:10:34'));
    $server = Server::factory()->create(['probe_interval' => 60]);
    $this->ssh->on($server->ssh_alias, 'ping', FakeSshClient::ok())->on($server->ssh_alias, 'probe', probeOutput());

    ProbeServer::dispatchSync($server->id);

    expect($server->refresh()->next_probe_at->equalTo(Carbon::parse('2026-10-15 17:11:00')))->toBeTrue();
});

it('leaves a scheduled probe that succeeds out of the audit log, and records one that fails', function () {
    $server = Server::factory()->create();
    $this->app->instance(SshClient::class, new AuditingSshClient($this->ssh));
    $this->ssh->on($server->ssh_alias, 'ping', FakeSshClient::ok())->on($server->ssh_alias, 'probe', probeOutput());

    ProbeServer::dispatchSync($server->id);

    expect($server->snapshots()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(0);

    $this->ssh->on($server->ssh_alias, 'ping', new HostUnreachable('ssh: connect to host 203.0.113.10 port 22: Connection timed out'));

    ProbeServer::dispatchSync($server->id);

    expect(AuditEntry::query()->where('action', AuditAction::RemoteCommand->value)->pluck('outcome')->unique()->all())
        ->toBe([AuditOutcome::Failed]);
});

it('audits a probe someone runs by hand', function () {
    $server = Server::factory()->create();
    $this->actingAs(User::factory()->create(['name' => 'Roman']));
    $this->app->instance(SshClient::class, new AuditingSshClient($this->ssh));
    $this->ssh->on($server->ssh_alias, 'ping', FakeSshClient::ok())->on($server->ssh_alias, 'probe', probeOutput());

    app(RecordProbe::class)($server);

    expect(AuditEntry::query()->orderBy('id')->pluck('subject')->all())->toBe(["ping on {$server->ssh_alias}", "probe on {$server->ssh_alias}"])
        ->and(AuditEntry::query()->pluck('actor')->unique()->all())->toBe(['Roman']);
});
