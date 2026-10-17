<?php

use App\Support\EnvFile;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'deployme-env');
    file_put_contents($this->path, "# comment\nAPP_NAME=deploy.me\nEMPTY=\n");
});

afterEach(fn () => @unlink($this->path));

it('reads values', function () {
    $env = new EnvFile($this->path);

    expect($env->get('APP_NAME'))->toBe('deploy.me')
        ->and($env->get('EMPTY'))->toBe('')
        ->and($env->get('MISSING'))->toBeNull();
});

it('replaces in place, appends new keys and keeps comments', function () {
    (new EnvFile($this->path))->set(['EMPTY' => 'filled', 'NEW_KEY' => 'value']);

    expect(file_get_contents($this->path))->toBe("# comment\nAPP_NAME=deploy.me\nEMPTY=filled\nNEW_KEY=value\n");
});

it('quotes values that need it', function () {
    (new EnvFile($this->path))->set(['EMPTY' => 'two words $HOME']);

    expect((new EnvFile($this->path))->get('EMPTY'))->toBe('two words $HOME')
        ->and(file_get_contents($this->path))->toContain('EMPTY="two words $HOME"');
});

it('refuses keys that are not env names', function () {
    (new EnvFile($this->path))->set(['bad key' => 'x']);
})->throws(InvalidArgumentException::class);
