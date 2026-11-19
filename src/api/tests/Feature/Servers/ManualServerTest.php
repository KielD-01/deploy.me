<?php

use App\Enums\AuditAction;
use App\Livewire\Servers\Create;
use App\Livewire\Servers\Show;
use App\Models\AuditEntry;
use App\Models\ManualHost;
use App\Models\Server;
use App\Models\User;
use App\Platform\Ssh\Exceptions\AuthenticationFailed;
use App\Platform\Ssh\Exceptions\HostKeyUnknown;
use App\Platform\Ssh\HostKeyScan;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;
use App\Platform\Ssh\SshTargetResolver;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

// Servers typed in by hand: the real compiler and `ssh -G` resolve them (nothing
// connects for that), the fake ssh client answers the connections.
beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    session(['auth.password_confirmed_at' => time()]);

    $this->dir = sys_get_temp_dir().'/deployme-manual-'.bin2hex(random_bytes(4));
    $this->sshDir = "{$this->dir}/ssh";
    mkdir($this->sshDir, 0700, true);
    file_put_contents("{$this->sshDir}/config", "Host build-box\n  HostName 198.51.100.23\nHost bastion\n  HostName 198.51.100.1\n  User jump\n");
    config([
        'deployme.ssh.source_config' => "{$this->sshDir}/config",
        'deployme.ssh.user_known_hosts' => "{$this->sshDir}/known_hosts",
        'deployme.ssh.extra_configs' => [],
        'deployme.ssh.runtime_dir' => "{$this->dir}/run",
        'deployme.ssh.agent_socket' => null,
        'deployme.host_home' => '/Users/me',
    ]);
    $this->app->forgetInstance(SshConfigCompiler::class);
    $this->app->forgetInstance(SshTargetResolver::class);

    $this->key = privateKey($this->sshDir, 'kz_deploy');

    $this->ssh = (new FakeSshClient)
        ->on('kz-prod', 'ping', FakeSshClient::ok())
        ->on('kz-prod', 'probe', probeOutput())
        ->on('kz-prod', 'is-dir', FakeSshClient::ok())
        ->on('kz-prod', 'ls', lsOutput('/opt', [['srv', 'deploy', '775', true]]));
    $this->app->instance(SshClient::class, $this->ssh);
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->dir)));

/**
 * A file that starts like a private key (the body is made up), only its owner may read.
 */
function privateKey(string $dir, string $name, int $mode = 0600): string
{
    $path = "{$dir}/{$name}";
    file_put_contents($path, "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAAnotarealkey\n-----END OPENSSH PRIVATE KEY-----\n");
    chmod($path, $mode);

    return $path;
}

function typedServer(array $fields = []): Testable
{
    $page = Livewire::test(Create::class)->call('enterManually');

    foreach (['manualName' => 'KZ Prod', 'manualUser' => 'deploy', 'manualHost' => '203.0.113.10', 'manualPort' => '2222', 'manualKey' => '~/.ssh/kz_deploy', ...$fields] as $field => $value) {
        $page->set($field, $value);
    }

    return $page;
}

function kzProd(array $attributes = []): Server
{
    ManualHost::query()->create(['alias' => 'kz-prod', 'host_name' => '203.0.113.10', 'user' => 'deploy', 'port' => 2222, 'identity_file' => '~/.ssh/kz_deploy']);

    return Server::factory()->create(['ssh_alias' => 'kz-prod', 'name' => 'KZ Prod', 'host_name' => '203.0.113.10', 'ssh_user' => 'deploy', 'port' => 2222, ...$attributes]);
}

it('adds a server typed in by hand, the alias following its name, and checks it like any other', function () {
    $page = typedServer()->assertSet('manualAlias', 'kz-prod')
        ->call('toConnection')
        ->assertHasNoErrors()
        ->assertSet('step', 2)
        ->assertSet('alias', 'kz-prod')
        // ssh -G names the key as the containers see it; the page as it was typed.
        ->assertSet('target.identityFiles', ['~/.ssh/kz_deploy'])
        ->assertSet('target.port', 2222)
        ->assertSee('Docker 28.3.2');

    expect(ManualHost::query()->sole()->only(['alias', 'host_name', 'user', 'port', 'identity_file', 'proxy_jump']))->toBe([
        'alias' => 'kz-prod', 'host_name' => '203.0.113.10', 'user' => 'deploy', 'port' => 2222, 'identity_file' => '~/.ssh/kz_deploy', 'proxy_jump' => null,
    ])
        ->and(file_get_contents(config('deployme.ssh.runtime_dir').'/config'))->toContain("Host kz-prod\n  HostName 203.0.113.10\n  User deploy\n  Port 2222\n  IdentityFile {$this->key}\n  IdentitiesOnly yes");

    $page->call('toPath')
        ->set('path', '/opt/srv')
        ->call('toReview')
        ->assertSet('name', 'KZ Prod')
        ->call('save')
        ->assertRedirect(route('servers.show', 'kz-prod'));

    expect(Server::query()->sole())
        ->ssh_alias->toBe('kz-prod')
        ->ssh_user->toBe('deploy')
        ->port->toBe(2222)
        ->identity_files->toBe(['~/.ssh/kz_deploy'])
        ->and(AuditEntry::query()->where('action', AuditAction::ManualHostSaved->value)->sole())
        ->subject->toBe('kz-prod')
        ->detail->toBe('added');
});

it('reads user@host:port typed into the host field, and a key pasted with -i', function () {
    typedServer(['manualUser' => '', 'manualPort' => '22', 'manualHost' => 'root@203.0.113.10:2200', 'manualKey' => '-i /Users/me/.ssh/kz_deploy'])
        ->call('toConnection')
        ->assertHasNoErrors()
        ->assertSet('manualUser', 'root')
        ->assertSet('manualHost', '203.0.113.10')
        ->assertSet('manualPort', '2200')
        ->assertSet('manualKey', '~/.ssh/kz_deploy');
});

it('works without a key, with the agent’s', function () {
    typedServer(['manualKey' => ''])->call('toConnection')->assertHasNoErrors()->assertSet('step', 2);

    expect(ManualHost::query()->sole()->identity_file)->toBeNull()
        ->and(file_get_contents(config('deployme.ssh.runtime_dir').'/config'))->not->toContain('IdentitiesOnly');
});

it('says what’s wrong with each field, and saves nothing', function () {
    typedServer(['manualName' => '', 'manualAlias' => 'build-box', 'manualHost' => 'not a host', 'manualUser' => 'root user', 'manualPort' => '70000', 'manualKey' => '~/Downloads/kz.pem', 'manualJump' => 'nowhere'])
        ->call('toConnection')
        ->assertSet('step', 1)
        ->assertHasErrors(['manualAlias', 'manualHost', 'manualUser', 'manualPort', 'manualKey', 'manualJump'])
        ->assertSee('build-box is already a Host in your ssh config')
        ->assertSee('Use a host name or an IP address')
        ->assertSee('Use a port from 1 to 65535')
        ->assertSee('cp ~/Downloads/kz.pem ~/.ssh/kz.pem &amp;&amp; chmod 600 ~/.ssh/kz.pem', false);

    expect(ManualHost::query()->count())->toBe(0);
});

it('checks a field as soon as you leave it, and clears its problem once it’s fixed', function () {
    privateKey($this->sshDir, 'kz_open', 0644);

    Livewire::test(Create::class)->call('enterManually')
        ->set('manualKey', '~/.ssh/kz_open')
        ->assertHasErrors('manualKey')
        ->assertSee('chmod 600 ~/.ssh/kz_open')
        // The fields not filled in yet wait for Continue.
        ->assertHasNoErrors(['manualHost', 'manualUser', 'manualAlias'])
        ->set('manualKey', '~/.ssh/kz_deploy')
        ->assertHasNoErrors('manualKey')
        ->set('manualPort', '0')
        ->assertHasErrors('manualPort');

    expect(ManualHost::query()->count())->toBe(0);
});

it('names the chmod for a key others can read, and refuses a public key or a missing one', function () {
    privateKey($this->sshDir, 'kz_open', 0644);
    file_put_contents("{$this->sshDir}/kz_deploy.pub", "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIexample me@mac\n");

    typedServer(['manualKey' => '~/.ssh/kz_open'])->call('toConnection')
        ->assertHasErrors('manualKey')
        ->assertSee('ssh refuses a private key others can read, and ~/.ssh/kz_open is 644. Run on this machine: chmod 600 ~/.ssh/kz_open');

    typedServer(['manualKey' => '~/.ssh/kz_deploy.pub'])->call('toConnection')->assertSee('Use the private one: ~/.ssh/kz_deploy');
    typedServer(['manualKey' => '~/.ssh/kz_missing'])->call('toConnection')->assertSee('There’s no file at ~/.ssh/kz_missing');

    expect(ManualHost::query()->count())->toBe(0);
});

it('goes through a jump host from the ssh config, and refuses a loop', function () {
    typedServer(['manualJump' => 'bastion'])->call('toConnection')->assertHasNoErrors()->assertSet('target.proxyJump', 'bastion');

    ManualHost::query()->create(['alias' => 'inner', 'host_name' => '10.0.0.5', 'user' => 'deploy', 'port' => 22, 'proxy_jump' => 'kz-prod']);

    Livewire::test(Show::class, ['server' => Server::factory()->create(['ssh_alias' => 'kz-prod'])])
        ->set('tab', 'settings')
        ->set('manualJump', 'inner')
        ->call('saveConnection')
        ->assertHasErrors('manualJump')
        ->assertSee('inner already goes through kz-prod: that would be a loop.');
});

it('saves the same server again after Back, not a second one', function () {
    typedServer()->call('toConnection')->assertSet('step', 2)
        ->call('toPath')->set('path', '/opt/srv')->call('toReview')->assertSet('name', 'KZ Prod')
        ->call('back')->call('back')->call('back')
        ->assertSet('step', 1)
        ->set('manualHost', '203.0.113.11')
        ->set('manualName', 'KZ Production')
        ->call('toConnection')
        ->assertSet('step', 2)
        ->call('toPath')->call('toReview')
        ->assertSet('name', 'KZ Production');

    expect(ManualHost::query()->sole()->host_name)->toBe('203.0.113.11');
});

it('stops at the connection check when the server refuses the key', function () {
    $this->ssh->on('kz-prod', 'ping', new AuthenticationFailed('deploy@203.0.113.10: Permission denied (publickey).'));

    typedServer()->call('toConnection')->assertSet('step', 2)->assertSee('The server refused the key')->assertSet('probe', null);
});

it('lists a server typed in earlier, to pick, change or forget while no server uses it', function () {
    ManualHost::query()->create(['alias' => 'left-over', 'host_name' => '198.51.100.9', 'user' => 'deploy', 'port' => 22]);

    $page = Livewire::test(Create::class)
        ->assertSee('left-over')
        ->assertSee('Manual')
        ->assertSee('1 entered here')
        ->call('editManual', 'left-over')
        ->assertSet('mode', 'manual')
        ->assertSet('manualHost', '198.51.100.9')
        ->call('pickFromList')
        ->call('deleteManual', 'left-over');

    expect(ManualHost::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', AuditAction::ManualHostRemoved->value)->sole()->subject)->toBe('left-over');
});

it('keeps a server’s connection while the server uses it', function () {
    kzProd();

    Livewire::test(Create::class)->call('deleteManual', 'kz-prod')->call('editManual', 'kz-prod')->assertSet('mode', 'config');

    expect(ManualHost::query()->count())->toBe(1);
});

it('asks for a passkey again before saving a server typed in', function () {
    $page = typedServer();
    session(['auth.password_confirmed_at' => time() - (int) config('auth.password_timeout', 10800) - 60]);

    $page->call('toConnection')->assertRedirect(route('password.confirm'));

    expect(ManualHost::query()->count())->toBe(0);
});

it('shows the connection of a server typed in on its Settings tab, in both themes', function () {
    $server = kzProd();
    $settings = fn (Server $server) => route('servers.show', ['server' => $server, 'tab' => 'settings']);

    $this->get($settings($server))->assertOk()
        ->assertSee('ssh.conf')
        ->assertSee('Host kz-prod · entered by hand')
        ->assertSee('identityfile')
        ->assertSee('proxyjump')
        ->assertSee('test connection');

    asClassic();

    $this->get($settings($server))->assertOk()
        ->assertSee('SSH connection')
        ->assertSee('Entered by hand')
        ->assertSee('Key file')
        ->assertSee('Jump host')
        ->assertSee('Test connection');

    $this->get($settings(Server::factory()->create(['ssh_alias' => 'build-box'])))->assertOk()
        ->assertDontSee('SSH connection');
});

it('saves a changed connection after a passkey, then tests it', function () {
    $server = kzProd();
    $page = Livewire::test(Show::class, ['server' => $server])->set('tab', 'settings')->set('manualPort', '22');

    session(['auth.password_confirmed_at' => time() - (int) config('auth.password_timeout', 10800) - 60]);
    $page->call('saveConnection')->assertRedirect(route('password.confirm'));
    expect(ManualHost::query()->sole()->port)->toBe(2222);

    session(['auth.password_confirmed_at' => time()]);
    $page->set('manualHost', '203.0.113.20')
        ->set('manualKey', '')
        ->call('saveConnection')
        ->assertHasNoErrors()
        ->assertSet('connectionNotice', 'Connection saved.')
        ->assertSet('connectionTest.ok', true)
        ->assertSee('as deploy@203.0.113.20 with the keys your ssh-agent holds');

    expect(ManualHost::query()->sole()->only(['host_name', 'port', 'identity_file']))->toBe(['host_name' => '203.0.113.20', 'port' => 22, 'identity_file' => null])
        ->and($server->refresh())->host_name->toBe('203.0.113.20')->port->toBe(22)
        ->and(AuditEntry::query()->where('action', AuditAction::ManualHostSaved->value)->sole()->detail)->toBe('changed host, port, key');
});

it('closes the connection ssh keeps open once the key changed, so the test signs in with the new one', function () {
    $server = kzProd();
    privateKey($this->sshDir, 'kz_new');
    Process::fake(["*'-O' 'exit' 'kz-prod'" => Process::result()]);

    Livewire::test(Show::class, ['server' => $server])->set('tab', 'settings')
        ->set('manualKey', '~/.ssh/kz_new')
        ->call('saveConnection')
        ->assertHasNoErrors()
        ->assertSet('connectionTest.ok', true)
        ->assertSee('with ~/.ssh/kz_new');

    Process::assertRan(fn (PendingProcess $process) => is_array($process->command) && array_slice($process->command, -3) === ['-O', 'exit', 'kz-prod']);
    expect($server->refresh()->identity_files)->toBe(['~/.ssh/kz_new']);
});

it('tests the connection and says what went wrong', function () {
    $server = kzProd();
    $this->ssh->on('kz-prod', 'ping', new AuthenticationFailed('Permission denied (publickey).', "Permissions 0644 for '{$this->key}' are too open.\nPermission denied (publickey)."));

    Livewire::test(Show::class, ['server' => $server])->set('tab', 'settings')
        ->call('testConnection')
        ->assertSet('connectionTest.ok', false)
        ->assertSee('ssh ignored the key ~/.ssh/kz_deploy: others can read it (0644). Run on this machine: chmod 600 ~/.ssh/kz_deploy');
});

it('shows a new host key after the host changed, and trusts it once compared', function () {
    $server = kzProd();
    $scan = new HostKeyScan('kz-prod', ['[203.0.113.10]:2222 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest'], [['type' => 'ED25519', 'fingerprint' => 'SHA256:kz123']]);
    $knownHosts = $this->mock(KnownHosts::class);
    $knownHosts->shouldReceive('scan')->andReturn($scan);
    $knownHosts->shouldReceive('trust')->once();

    $trusted = false;
    $this->ssh->on('kz-prod', 'ping', function () use (&$trusted) {
        return $trusted ? FakeSshClient::ok() : throw new HostKeyUnknown('No ED25519 host key is known for [203.0.113.10]:2222');
    });

    $page = Livewire::test(Show::class, ['server' => $server])->set('tab', 'settings')
        ->call('testConnection')
        ->assertSee('SHA256:kz123')
        ->assertSee('isn’t trusted yet');

    $trusted = true;

    $page->call('trustConnectionKey')
        ->assertSet('connectionHostKey', null)
        ->assertSet('connectionTest.ok', true);
});

it('forgets the connection typed in with the server it was for', function () {
    $server = kzProd();

    Livewire::test(Show::class, ['server' => $server])->call('archive')->assertRedirect(route('servers.index'));

    expect(ManualHost::query()->count())->toBe(0)
        ->and($server->refresh()->archived_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('action', AuditAction::ManualHostRemoved->value)->sole()->detail)->toBe('with the server');

    // The alias is free for a new one.
    typedServer()->call('toConnection')->assertHasNoErrors()->assertSet('step', 2);
});

it('refuses an alias a server already goes by', function () {
    Server::factory()->create(['ssh_alias' => 'old-box']);

    typedServer(['manualAlias' => 'old-box'])->call('toConnection')->assertHasErrors('manualAlias')->assertSee('A server here already goes by old-box.');
});
