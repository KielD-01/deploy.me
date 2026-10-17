<?php

use App\Platform\Ssh\SshAlias;
use App\Platform\Ssh\SshConfigReader;

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/deployme-ssh-'.bin2hex(random_bytes(4));
    mkdir($this->home.'/.ssh/conf.d', 0700, true);

    file_put_contents($this->home.'/.ssh/config', <<<'CFG'
        # personal servers
        Host prod staging
          HostName 203.0.113.10
          User deploy
        Host *.internal !bastion
          User ops
        Host gh-work
          HostName github.com
        Include conf.d/*.conf
        Match host something
          HostName ignored
        Host prod
          HostName 198.51.100.1
        CFG);

    file_put_contents($this->home.'/.ssh/conf.d/extra.conf', "Host nas\n  HostName=192.0.2.20\n");

    $this->reader = new SshConfigReader([$this->home.'/.ssh/config'], $this->home);
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->home)));

it('lists literal hosts in order and follows Include', function () {
    expect(array_map(fn ($alias) => $alias->name, $this->reader->aliases()))->toBe(['prod', 'staging', 'gh-work', 'nas']);
});

it('keeps the first HostName, like ssh', function () {
    expect($this->reader->find('prod')->hostName)->toBe('203.0.113.10')
        ->and($this->reader->find('staging')->hostName)->toBe('203.0.113.10')
        ->and($this->reader->find('nas')->hostName)->toBe('192.0.2.20');
});

it('flags aliases that point at a git host', function () {
    expect($this->reader->find('gh-work')->looksLikeGitHost())->toBeTrue()
        ->and($this->reader->find('prod')->looksLikeGitHost())->toBeFalse();
});

it('records where each alias is defined', function () {
    expect($this->reader->find('prod')->line)->toBe(2)
        ->and(basename($this->reader->find('nas')->sourceFile))->toBe('extra.conf');
});

it('refuses aliases that could read as ssh options', function (string $alias) {
    SshAlias::assertValid($alias);
})->with(['-oProxyCommand=evil', 'two words', "new\nline", ''])->throws(InvalidArgumentException::class);
