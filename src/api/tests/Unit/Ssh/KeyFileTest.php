<?php

use App\Platform\Ssh\KeyFile;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/deployme-keyfile-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/.ssh/keys', 0700, true);
    $this->ssh = $this->dir.'/.ssh';
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->dir)));

/**
 * A file in the temporary ~/.ssh: a fake key body under a real header, never a key.
 */
function keyFile(string $dir, string $name, string $firstLine = '-----BEGIN OPENSSH PRIVATE KEY-----', int $mode = 0600): string
{
    $path = "{$dir}/{$name}";
    file_put_contents($path, $firstLine."\nb3BlbnNzaC1rZXktdjEAAAAAnotarealkey\n-----END OPENSSH PRIVATE KEY-----\n");
    chmod($path, $mode);

    return $path;
}

function parsed(string $typed): KeyFile
{
    return KeyFile::parse($typed, '/Users/me', '/root/.ssh');
}

function refusal(string $typed): string
{
    try {
        parsed($typed);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException("{$typed} was accepted");
}

it('reads every way of naming a key inside ~/.ssh as the same file', function (string $typed) {
    $key = parsed($typed);

    expect($key->name)->toBe('kz_deploy')
        ->and($key->display())->toBe('~/.ssh/kz_deploy')
        ->and($key->path('/root/.ssh'))->toBe('/root/.ssh/kz_deploy');
})->with([
    '~/.ssh/kz_deploy',
    '$HOME/.ssh/kz_deploy',
    '${HOME}/.ssh/kz_deploy',
    'the full path on this machine' => '/Users/me/.ssh/kz_deploy',
    'the path in the containers' => '/root/.ssh/kz_deploy',
    'pasted from ssh -i' => '-i ~/.ssh/kz_deploy',
    'quoted' => '"~/.ssh/kz_deploy"',
    'with spaces around' => '  ~/.ssh/kz_deploy ',
    'spelled loosely' => '~/.ssh//./kz_deploy',
]);

it('keeps folders inside ~/.ssh', function () {
    expect(parsed('~/.ssh/keys/kz')->path('/root/.ssh'))->toBe('/root/.ssh/keys/kz')
        ->and(parsed('/Users/me/.ssh/keys/kz')->display())->toBe('~/.ssh/keys/kz');
});

it('refuses a key outside ~/.ssh and says how to bring it in', function () {
    expect(refusal('/Users/me/Downloads/kz.pem'))
        ->toContain('only sees your ~/.ssh folder')
        ->toContain('cp /Users/me/Downloads/kz.pem ~/.ssh/kz.pem && chmod 600 ~/.ssh/kz.pem')
        ->and(refusal('~/keys/kz'))->toContain('cp ~/keys/kz ~/.ssh/kz')
        ->and(refusal('/etc/ssh/ssh_host_ed25519_key'))->toContain('only sees your ~/.ssh folder')
        ->and(refusal('~other/.ssh/kz'))->toContain('only sees your ~/.ssh folder');
});

it('asks for the full path, a file name, and a path without ..', function () {
    expect(refusal('kz_deploy'))->toBe('Type the full path, like ~/.ssh/kz_deploy.')
        ->and(refusal('~/.ssh/'))->toContain('name too')
        ->and(refusal('~/.ssh/../Downloads/kz'))->toContain('without “..”')
        ->and(refusal(''))->toContain('Type the path to the key');
});

it('refuses what the ssh config would read as something else', function (string $typed) {
    expect(refusal($typed))->toContain('only letters, digits, spaces');
})->with(['~/.ssh/k%h', '~/.ssh/k"ey', '~/.ssh/${KEY}', "~/.ssh/k'ey", '~/.ssh/k\\ey']);

it('refuses a path on more than one line', function () {
    expect(refusal("~/.ssh/kz\nProxyCommand x"))->toContain('on one line');
});

it('takes a private key only it can read', function (string $header) {
    keyFile($this->ssh, 'kz_deploy', $header);

    expect(parsed('~/.ssh/kz_deploy')->problem($this->ssh))->toBeNull();
})->with([
    '-----BEGIN OPENSSH PRIVATE KEY-----',
    '-----BEGIN RSA PRIVATE KEY-----',
    '-----BEGIN EC PRIVATE KEY-----',
    '-----BEGIN PRIVATE KEY-----',
    '-----BEGIN ENCRYPTED PRIVATE KEY-----',
]);

it('names the chmod that fixes a key others can read', function (int $mode) {
    keyFile($this->ssh, 'kz_deploy', mode: $mode);

    expect(parsed('~/.ssh/kz_deploy')->problem($this->ssh))
        ->toBe(sprintf('ssh refuses a private key others can read, and ~/.ssh/kz_deploy is %o. Run on this machine: chmod 600 ~/.ssh/kz_deploy', $mode));
})->with([0644, 0640, 0604]);

it('says what a file that isn’t a usable private key is', function () {
    keyFile($this->ssh, 'id_ed25519.pub', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIexample me@mac', 0644);
    keyFile($this->ssh, 'other', 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABexample me@mac', 0600);
    keyFile($this->ssh, 'kz.ppk', 'PuTTY-User-Key-File-3: ssh-ed25519');
    keyFile($this->ssh, 'notes.txt', 'remember the milk');
    touch($this->ssh.'/empty');

    expect(parsed('~/.ssh/id_ed25519.pub')->problem($this->ssh))->toBe('~/.ssh/id_ed25519.pub is the public key. Use the private one: ~/.ssh/id_ed25519')
        ->and(parsed('~/.ssh/other')->problem($this->ssh))->toContain('is a public key')
        ->and(parsed('~/.ssh/kz.ppk')->problem($this->ssh))->toContain('PuTTY key')->toContain('puttygen ~/.ssh/kz.ppk -O private-openssh -o ~/.ssh/kz')
        ->and(parsed('~/.ssh/notes.txt')->problem($this->ssh))->toContain('isn’t a private key')
        ->and(parsed('~/.ssh/empty')->problem($this->ssh))->toBe('~/.ssh/empty is empty.')
        ->and(parsed('~/.ssh/missing')->problem($this->ssh))->toContain('There’s no file at ~/.ssh/missing')
        ->and(parsed('~/.ssh/keys')->problem($this->ssh))->toBe('~/.ssh/keys is a folder. Point to the key file inside it.');
});

it('refuses a file too big to be a key without reading it', function () {
    $path = keyFile($this->ssh, 'huge');
    file_put_contents($path, str_repeat('A', 70 * 1024), FILE_APPEND);

    expect(parsed('~/.ssh/huge')->problem($this->ssh))->toContain('too big to be a private key');
});
