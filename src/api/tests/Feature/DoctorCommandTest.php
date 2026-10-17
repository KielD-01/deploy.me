<?php

use App\Models\Credential;
use App\Models\Server;
use App\Models\SshHostKey;
use App\Platform\Ssh\Exceptions\SshConfigError;
use App\Platform\Ssh\SshTarget;
use App\Platform\Ssh\SshTargetResolver;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    // The parts of doctor this test isn't about: the broadcast round trip and
    // the scheduler's heartbeat.
    Broadcast::shouldReceive('connection')->andReturnSelf();
    Broadcast::shouldReceive('broadcast')->andReturnNull();
    Cache::put('deployme:scheduler:heartbeat', now()->getTimestamp());

    $this->keys = sys_get_temp_dir().'/deployme-doctor-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->keys);
});

afterEach(fn () => File::deleteDirectory($this->keys));

function doctorKey(object $test, string $name, int $mode): string
{
    $path = "{$test->keys}/{$name}";
    file_put_contents($path, "-----BEGIN OPENSSH PRIVATE KEY-----\n");
    chmod($path, $mode);

    return $path;
}

function doctorTarget(string $alias, array $identityFiles): SshTarget
{
    return new SshTarget($alias, "{$alias}.example.com", 'deploy', 22, $identityFiles, null, null, true);
}

it('checks every server alias: that ssh resolves it, its keys and its host key', function () {
    $good = Server::factory()->create(['ssh_alias' => 'box']);
    Server::factory()->create(['ssh_alias' => 'gone']);
    SshHostKey::query()->create([
        'alias' => 'box', 'host_pattern' => 'box.example.com', 'key_type' => 'ssh-ed25519',
        'known_hosts_line' => 'box.example.com ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:x', 'trusted_at' => now(),
    ]);
    // Archived servers are nobody's problem any more.
    Server::factory()->create(['ssh_alias' => 'retired', 'archived_at' => now()]);

    $this->mock(SshTargetResolver::class, function ($mock) {
        $mock->shouldReceive('resolve')->with('box')->andReturn(doctorTarget('box', [doctorKey($this, 'id_ed25519', 0600)]));
        $mock->shouldReceive('resolve')->with('gone')->andThrow(new SshConfigError('Bad configuration option: usekeychain'));
    });

    $this->artisan('deployme:doctor')
        ->expectsOutputToContain('✓ ssh -G box resolves')
        ->expectsOutputToContain('deploy@box.example.com:22')
        ->expectsOutputToContain('✓ box: the keys ssh would offer are private')
        ->expectsOutputToContain('✓ box: its host key is trusted')
        ->expectsOutputToContain('✗ ssh -G gone resolves')
        ->doesntExpectOutputToContain('retired')
        ->assertFailed();

    expect($good->ssh_alias)->toBe('box');
});

it('says which key file is too readable, without failing the run over it', function () {
    Server::factory()->create(['ssh_alias' => 'box']);
    SshHostKey::query()->create([
        'alias' => 'box', 'host_pattern' => 'box.example.com', 'key_type' => 'ssh-ed25519',
        'known_hosts_line' => 'box.example.com ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:x', 'trusted_at' => now(),
    ]);
    $key = doctorKey($this, 'id_rsa', 0644);

    $this->mock(SshTargetResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->with('box')->andReturn(doctorTarget('box', [$key, "{$this->keys}/absent"])));

    $this->artisan('deployme:doctor')
        // One expectation per line: each matches a single write.
        ->expectsOutputToContain("! box: the keys ssh would offer are private — {$key} is 644, ssh wants 600")
        ->assertSuccessful();
});

it('says when a server’s host key is trusted nowhere', function () {
    Server::factory()->create(['ssh_alias' => 'box']);
    config(['deployme.ssh.user_known_hosts' => "{$this->keys}/known_hosts"]);

    $this->mock(SshTargetResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->with('box')->andReturn(doctorTarget('box', [])));

    $this->artisan('deployme:doctor')
        ->expectsOutputToContain('✗ box: its host key is trusted — nothing trusted for box.example.com; add the server again to trust it')
        ->assertFailed();
});

it('checks each GitHub token’s expiry and scopes', function () {
    Credential::factory()->create(['login' => 'expired-one', 'token_type' => 'classic', 'scopes' => ['repo', 'read:packages'], 'expires_at' => now()->subDay()]);
    Credential::factory()->create(['login' => 'soon-one', 'token_type' => 'classic', 'scopes' => ['repo', 'read:packages'], 'expires_at' => now()->addDays(3)]);
    Credential::factory()->create(['login' => 'thin-one', 'token_type' => 'classic', 'scopes' => ['repo'], 'expires_at' => null]);
    Credential::factory()->create(['login' => 'fine-one', 'token_type' => 'fine_grained', 'scopes' => [], 'expires_at' => null]);

    $this->artisan('deployme:doctor')
        ->expectsOutputToContain('✗ GitHub token for expired-one hasn’t expired')
        ->expectsOutputToContain('! GitHub token for soon-one expires no time soon')
        ->expectsOutputToContain('! GitHub token for thin-one can read repositories and packages — missing read:packages')
        ->expectsOutputToContain('! GitHub token for fine-one can read repositories and packages — fine-grained: check its repository and package permissions on GitHub')
        ->assertFailed();
});
