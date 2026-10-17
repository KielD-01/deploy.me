<?php

use App\Livewire\Servers\Create;
use App\Models\Server;
use App\Models\User;
use App\Platform\Ssh\Exceptions\AuthenticationFailed;
use App\Platform\Ssh\Exceptions\HostKeyUnknown;
use App\Platform\Ssh\HostKeyScan;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigReader;
use App\Platform\Ssh\SshTarget;
use App\Platform\Ssh\SshTargetResolver;
use App\Platform\Ssh\Testing\FakeSshClient;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());

    $dir = sys_get_temp_dir().'/deployme-wizard-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    file_put_contents("{$dir}/config", "Host build-box\n  HostName 198.51.100.23\nHost gh-work\n  HostName github.com\n");
    $this->app->instance(SshConfigReader::class, new SshConfigReader(["{$dir}/config"], $dir));

    $this->mock(SshTargetResolver::class)
        ->shouldReceive('resolve')
        ->andReturn(new SshTarget('build-box', '198.51.100.23', 'ci', 22, ['~/.ssh/id_rsa_build'], null, null, true));

    $this->ssh = (new FakeSshClient)
        ->on('build-box', 'probe', probeOutput())
        ->on('build-box', 'is-dir', FakeSshClient::ok())
        ->on('build-box', 'ls', lsOutput('/opt', [['infra', 'root', '755', false], ['srv', 'ci', '775', true]]));
    $this->app->instance(SshClient::class, $this->ssh);
});

it('asks for a passkey before adding a server', function () {
    $this->get('/servers/create')->assertRedirect('/user/confirm-password');
});

it('lists hosts and hides git identities until asked', function () {
    Livewire::test(Create::class)
        ->assertSee('build-box')
        ->assertDontSee('gh-work')
        ->assertSee('Show git identities')
        ->set('showGitHosts', true)
        ->assertSee('gh-work');
});

it('trusts an unknown host key, probes, and saves the server', function () {
    $scan = new HostKeyScan('build-box', ['198.51.100.23 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest'], [['type' => 'ED25519', 'fingerprint' => 'SHA256:abc123']]);
    $knownHosts = $this->mock(KnownHosts::class);
    $knownHosts->shouldReceive('scan')->andReturn($scan);
    $knownHosts->shouldReceive('trust')->once();

    $trusted = false;
    $this->ssh->on('build-box', 'ping', function () use (&$trusted) {
        return $trusted ? FakeSshClient::ok() : throw new HostKeyUnknown('No ED25519 host key is known for build-box');
    });

    $wizard = Livewire::test(Create::class)
        ->call('select', 'build-box')
        ->assertSee('198.51.100.23')
        ->call('toConnection')
        ->assertSet('step', 2)
        ->assertSee('SHA256:abc123')
        ->assertSee('Trust and continue');

    $trusted = true;

    $wizard->call('trustHostKey')
        ->assertSet('hostKey', null)
        ->assertSee('Docker 28.3.2')
        ->call('toPath')
        ->assertSet('step', 3)
        ->assertSet('path', '/opt')
        ->assertSee('srv')
        ->set('path', '/opt/srv')
        ->call('toReview')
        ->assertSet('step', 4)
        ->assertSet('name', 'Build box')
        ->set('envTag', 'staging')
        ->call('save')
        ->assertRedirect(route('servers.show', 'build-box'));

    $server = Server::query()->sole();

    expect($server->ssh_alias)->toBe('build-box')
        ->and($server->default_deploy_path)->toBe('/opt/srv')
        ->and($server->env_tag->value)->toBe('staging')
        ->and($server->capabilities['docker'])->toBe('28.3.2')
        ->and($server->snapshots()->count())->toBe(1);
});

it('refuses a reserved deploy path', function () {
    $this->ssh->on('build-box', 'ping', FakeSshClient::ok());

    Livewire::test(Create::class)
        ->call('select', 'build-box')
        ->call('toConnection')
        ->call('toPath')
        ->set('path', '/opt/infra/apps')
        ->call('toReview')
        ->assertSet('step', 3)
        ->assertSee('reserved for the shared infrastructure');
});

it('stops when the server refuses the key', function () {
    $this->ssh->on('build-box', 'ping', new AuthenticationFailed('deploy@198.51.100.23: Permission denied (publickey).'));

    Livewire::test(Create::class)
        ->call('select', 'build-box')
        ->call('toConnection')
        ->assertSee('The server refused the key')
        ->assertSet('probe', null);
});
