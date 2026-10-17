<?php

use App\Actions\Servers\CheckRoute;
use App\Actions\Servers\RecordProbe;
use App\Actions\Targets\CheckTargetHealth;
use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\RouteKind;
use App\Enums\RouteState;
use App\Enums\ServerStatus;
use App\Enums\TargetHealth;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Events\ServerProbed;
use App\Livewire\Servers\Show;
use App\Models\AuditEntry;
use App\Models\Deployment;
use App\Models\RouteCheck;
use App\Models\Server;
use App\Models\User;
use App\Platform\Ssh\Route\RouteProbe;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshTarget;
use App\Platform\Ssh\SshTargetResolver;
use App\Platform\Ssh\Testing\FakeRouteProbe;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    $this->probe = new FakeRouteProbe;
    $this->app->instance(RouteProbe::class, $this->probe);
    $this->mock(SshTargetResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->andReturnUsing(
        fn (string $alias) => new SshTarget($alias, '10.20.0.5', 'deploy', 22, [], null, null, false),
    ));
    Event::fake([ServerProbed::class, DeploymentUpdated::class, DeploymentLogged::class]);
    Sleep::fake();
});

function socksServer(array $attributes = []): Server
{
    return Server::factory()->create([
        'ssh_alias' => 'box', 'name' => 'prod-eu', 'default_deploy_path' => '/opt/srv',
        'route_kind' => RouteKind::Socks5, 'route' => ['host' => '10.0.0.2', 'port' => 1080, 'user' => 'deploy'], 'route_secret' => 'pr0xy-secret',
        ...$attributes,
    ]);
}

it('has nothing to check for a server reached directly', function () {
    $server = Server::factory()->create();

    expect(app(CheckRoute::class)($server, 'manual'))->toBeNull()
        ->and($this->probe->calls)->toBe([])
        ->and(RouteCheck::query()->count())->toBe(0);
});

it('checks the proxy and the server through it, and keeps the result', function () {
    $server = socksServer();

    $check = app(CheckRoute::class)($server, 'manual');

    expect($check->state)->toBe(RouteState::Up)
        ->and($check->reason)->toBe('manual')
        ->and($check->detail)->toBe('SOCKS5 10.0.0.2:1080 → 10.20.0.5:22 answered SSH-2.0-OpenSSH_9.6p1')
        ->and($this->probe->calls)->toBe(['banner socks5 10.20.0.5:22'])
        ->and($server->refresh()->route_state)->toBe(RouteState::Up)
        ->and($server->route_checked_at)->not->toBeNull();
});

it('tells the route being down from the server not answering through it', function (string $how, RouteState $state) {
    $how === 'route' ? $this->probe->routeDown() : $this->probe->serverDown();

    $check = app(CheckRoute::class)(socksServer(), 'manual');

    expect($check->state)->toBe($state)
        ->and($check->detail)->toContain('10.0.0.2:1080');
})->with([
    'the proxy refuses' => ['route', RouteState::RouteDown],
    'the server refuses' => ['server', RouteState::ServerUnreachable],
]);

it('checks a VPN at its check address first, then the server directly', function () {
    $server = Server::factory()->create(['route_kind' => RouteKind::Vpn, 'route' => ['check_host' => '10.8.0.1', 'check_port' => 53]]);

    expect(app(CheckRoute::class)($server, 'manual')->detail)->toBe('VPN 10.8.0.1:53 answered · 10.20.0.5:22 answered SSH-2.0-OpenSSH_9.6p1')
        ->and($this->probe->calls)->toBe(['connect 10.8.0.1:53', 'banner vpn 10.20.0.5:22']);

    $this->probe->routeDown('10.8.0.1:53 didn’t answer within 5 s.');
    $check = app(CheckRoute::class)($server, 'manual');

    expect($check->state)->toBe(RouteState::RouteDown)
        ->and($check->detail)->toBe('Nothing answers at 10.8.0.1:53, the VPN’s check address: 10.8.0.1:53 didn’t answer within 5 s. Is the VPN connected on this machine?');
});

it('goes through the jump host for a jump route', function () {
    $server = Server::factory()->create(['route_kind' => RouteKind::Jump, 'route' => ['jump' => 'bastion']]);

    expect(app(CheckRoute::class)($server, 'manual')->detail)->toBe('jump host bastion → 10.20.0.5:22 answered SSH-2.0-OpenSSH_9.6p1')
        ->and($this->probe->calls)->toBe(['jump bastion 10.20.0.5:22']);
});

it('calls something that answers but isn’t ssh unreachable', function () {
    $this->probe->banner = 'HTTP/1.1 400 Bad Request';

    expect(app(CheckRoute::class)(socksServer(), 'manual')->state)->toBe(RouteState::ServerUnreachable);
});

it('keeps the proxy password encrypted and out of the server’s array form', function () {
    $server = socksServer();

    expect(DB::table('servers')->where('id', $server->id)->value('route_secret'))->not->toContain('pr0xy-secret')
        ->and($server->refresh()->route_secret)->toBe('pr0xy-secret')
        ->and(json_encode($server->toArray()))->not->toContain('pr0xy-secret');
});

it('doesn’t probe a server whose route is down, and says why', function () {
    $server = socksServer();
    $this->probe->routeDown();

    $snapshot = app(RecordProbe::class)($server, retry: true);

    expect($snapshot)->toBeNull()
        ->and($this->ssh->calls)->toBe([])
        ->and($server->refresh()->status)->toBe(ServerStatus::Offline)
        ->and($server->status_reason)->toBe('The route to prod-eu is down: The SOCKS5 proxy at 10.0.0.2:1080 refused the connection.')
        ->and($server->next_probe_at->lte(now()->addMinute()))->toBeTrue()
        ->and(RouteCheck::query()->sole()->reason)->toBe('probe');
});

it('probes as usual once the route is up', function () {
    $server = socksServer();
    $this->ssh->on('box', 'ping', FakeSshClient::ok())->on('box', 'probe', probeOutput());

    expect(app(RecordProbe::class)($server))->not->toBeNull()
        ->and($server->refresh()->status)->toBe(ServerStatus::Online)
        ->and($server->route_state)->toBe(RouteState::Up);
});

it('stops a deploy at pre-flight when the route is down, before anything reaches the server', function () {
    $target = demoTarget();
    $target->server->update(['name' => 'prod-eu', 'route_kind' => RouteKind::Socks5, 'route' => ['host' => '10.0.0.2', 'port' => 1080]]);
    fakeDeployScripts($this->ssh);
    $this->probe->routeDown();

    $deployment = Deployment::factory()->create(['target_id' => $target->id]);
    app(PipelineRunner::class)->run($deployment);
    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toContain('The route to prod-eu is down: The SOCKS5 proxy at 10.0.0.2:1080 refused the connection. Nothing on the server changed')
        ->and(collect($this->ssh->calls)->pluck('script.name')->reject(fn ($name) => $name === 'deploy-kill')->all())->toBe([])
        ->and(RouteCheck::query()->sole()->deployment_id)->toBe($deployment->id)
        ->and($deployment->logLines()->pluck('line')->implode("\n"))->toContain('route  SOCKS5 10.0.0.2:1080 · ✕ The SOCKS5 proxy');
});

it('notes the route in the pre-flight log when it’s up', function () {
    $target = demoTarget();
    $target->server->update(['route_kind' => RouteKind::Jump, 'route' => ['jump' => 'bastion']]);
    fakeDeployScripts($this->ssh);

    $deployment = Deployment::factory()->create(['target_id' => $target->id]);
    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->logLines()->pluck('line')->implode("\n"))->toContain('route  jump host bastion · jump host bastion → 10.20.0.5:22 answered');
});

it('leaves a target’s health alone while its server’s route is down', function () {
    $target = demoTarget(['health_url' => 'https://demo.test/up']);
    $target->update(['health' => TargetHealth::Healthy]);
    $target->server->update(['route_kind' => RouteKind::Vpn, 'route_state' => RouteState::RouteDown]);

    expect(app(CheckTargetHealth::class)($target))->toBeNull()
        ->and($target->refresh()->health)->toBe(TargetHealth::Healthy)
        ->and($this->ssh->calls)->toBe([]);
});

describe('the server page', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        asClassic();
        session(['auth.password_confirmed_at' => time()]);
    });

    it('asks for a passkey before it changes the route', function () {
        session()->forget('auth.password_confirmed_at');
        $server = Server::factory()->create(['ssh_alias' => 'box']);

        Livewire::test(Show::class, ['server' => $server])
            ->set('tab', 'settings')
            ->call('pickRouteKind', 'jump')
            ->set('routeJump', 'bastion')
            ->call('saveRoute')
            ->assertRedirect(route('password.confirm'));

        expect($server->refresh()->route_kind)->toBe(RouteKind::Direct);
    });

    it('saves a proxy route, keeps the password encrypted, and checks it straight away', function () {
        $server = Server::factory()->create(['ssh_alias' => 'box']);

        Livewire::test(Show::class, ['server' => $server])
            ->set('tab', 'settings')
            ->call('pickRouteKind', 'socks5')
            ->assertSet('routePort', '1080')
            ->set('routeHost', 'host.docker.internal')
            ->set('routeUser', 'deploy')
            ->set('routePassword', 'pr0xy-secret')
            ->call('saveRoute')
            ->assertHasNoErrors()
            ->assertSet('routePassword', '')
            ->assertSee('Route saved.')
            ->assertSee('Saved, encrypted. Type a new one to replace it.')
            ->assertDontSee('pr0xy-secret');

        $server->refresh();

        expect($server->route_kind)->toBe(RouteKind::Socks5)
            ->and($server->route)->toBe(['host' => 'host.docker.internal', 'port' => 1080, 'user' => 'deploy'])
            ->and($server->route_secret)->toBe('pr0xy-secret')
            ->and($server->route_state)->toBe(RouteState::Up)
            ->and(AuditEntry::query()->where('action', AuditAction::RouteChanged->value)->sole()->detail)->toBe('now SOCKS5 proxy')
            ->and(AuditEntry::query()->pluck('detail')->implode(' '))->not->toContain('pr0xy-secret');
    });

    it('keeps the saved password when the field is left empty, and drops it with the user', function () {
        $server = socksServer();
        $page = Livewire::test(Show::class, ['server' => $server])->set('tab', 'settings')->set('routePort', '1081')->call('saveRoute');

        expect($server->refresh()->route_secret)->toBe('pr0xy-secret')->and($server->route['port'])->toBe(1081);

        $page->set('routeUser', '')->call('saveRoute');

        expect($server->refresh()->route_secret)->toBeNull();
    });

    it('refuses a route it can’t use', function () {
        Livewire::test(Show::class, ['server' => Server::factory()->create(['ssh_alias' => 'box'])])
            ->set('tab', 'settings')
            ->call('pickRouteKind', 'jump')
            ->set('routeJump', '-oProxyCommand=sh')
            ->call('saveRoute')
            ->assertHasErrors('routeJump')
            ->assertSee('Use an alias from your ~/.ssh/config, or user@host:port.');

        expect(Server::query()->sole()->route_kind)->toBe(RouteKind::Direct);
    });

    it('checks the route by hand and lists the checks', function () {
        $server = socksServer();
        $this->probe->routeDown();

        Livewire::test(Show::class, ['server' => $server])
            ->assertSee('Check route')
            ->call('checkRoute')
            ->assertSee('route down')
            ->set('tab', 'checks')
            ->assertSee('Route checks')
            ->assertSee('checked by hand')
            ->assertSee('The SOCKS5 proxy at 10.0.0.2:1080 refused the connection.');
    });

    it('shows the route in the Console theme too', function () {
        auth()->user()->update(['ui' => ['theme' => 'console']]);
        $server = socksServer(['route_state' => RouteState::Up, 'route_detail' => 'SOCKS5 10.0.0.2:1080 → 10.20.0.5:22 answered SSH-2.0-x', 'route_checked_at' => now()]);

        $this->get(route('servers.show', ['server' => $server, 'tab' => 'settings']))
            ->assertOk()
            ->assertSee('route.conf')
            ->assertSee('proxy_host')
            ->assertSee('via socks5 10.0.0.2:1080')
            ->assertSee('check route')
            ->assertDontSee('pr0xy-secret');
    });
});

it('lists the route on the doctor’s server checks', function () {
    Broadcast::shouldReceive('connection')->andReturnSelf();
    Broadcast::shouldReceive('broadcast')->andReturnNull();
    Cache::put('deployme:scheduler:heartbeat', now()->getTimestamp());
    socksServer();

    $this->artisan('deployme:doctor')
        ->expectsOutputToContain('box: the route (SOCKS5 10.0.0.2:1080) is up');

    expect(RouteCheck::query()->sole()->reason)->toBe('doctor');
});
