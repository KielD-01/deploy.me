<?php

use App\Platform\Ssh\Exceptions\SshOutputTooLarge;
use App\Platform\Ssh\Exceptions\SshTimeout;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\LineBuffer;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshConfigCompiler;

// The real ProcessSshClient with a stand-in for the ssh binary: a shell script
// that prints whatever the test wants and ignores its arguments.
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/deployme-limit-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0700, true);

    config([
        'deployme.ssh.runtime_dir' => $this->dir.'/run',
        'deployme.ssh.user_known_hosts' => '/nonexistent',
        'deployme.ssh.source_config' => '/nonexistent',
        'deployme.ssh.extra_configs' => [],
    ]);

    $this->app->singleton(SshConfigCompiler::class, fn () => SshConfigCompiler::fromConfig());
    $this->app->singleton(KnownHosts::class);
    $this->client = fn () => app(ProcessSshClient::class);

    $this->fakeSsh = function (string $body): void {
        file_put_contents($this->dir.'/ssh', "#!/bin/sh\n{$body}\n");
        chmod($this->dir.'/ssh', 0700);
        config(['deployme.ssh.binary' => $this->dir.'/ssh']);
    };
});

afterEach(function () {
    foreach (glob($this->dir.'/{,run/,run/cm/}{*,.hash}', GLOB_BRACE) as $file) {
        is_file($file) && @unlink($file);
    }
    @rmdir($this->dir.'/run/cm');
    @rmdir($this->dir.'/run');
    @rmdir($this->dir);
});

it('ships with an 8 MB limit, and says so in the message', function () {
    expect(config('deployme.ssh.max_output_bytes'))->toBe(8 * 1024 * 1024)
        ->and((new SshOutputTooLarge(8 * 1024 * 1024))->getMessage())->toContain('The command printed more than 8 MB');
});

it('stops a command that never stops printing, keeping only the end of its output', function (?int $tickEvery) {
    config(['deployme.ssh.max_output_bytes' => 300_000]);
    ($this->fakeSsh)("echo 'something went wrong early' >&2\nexec yes 'abcdefghijklmnopqrstuvwxyz0123456789'");

    $lines = 0;
    $started = microtime(true);
    $caught = null;

    try {
        ($this->client)()->run('box', RemoteScript::inline('spam', 'true'), function () use (&$lines) {
            $lines++;
        }, timeout: 60, onTick: $tickEvery === null ? null : fn () => null);
    } catch (SshOutputTooLarge $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(SshOutputTooLarge::class)
        ->and($caught)->toBeInstanceOf(SshTimeout::class) // so a deploy lets go of the script like on a timeout
        ->and($caught->getMessage())->toBe('The command printed more than 300,000 bytes, so it was stopped.')
        ->and($caught->userMessage())->toBe($caught->getMessage())
        ->and(strlen($caught->stdout))->toBeLessThanOrEqual(65536)->toBeGreaterThan(1000)
        ->and($caught->stderr)->toContain('something went wrong early')
        // Not stopped by the 60 s timeout, and not left running to fill memory.
        ->and(microtime(true) - $started)->toBeLessThan(20)
        ->and($lines)->toBeGreaterThan(1000);
})->with(['without ticks' => [null], 'with ticks' => [1]]);

it('lets a command that prints less than the limit through untouched', function () {
    config(['deployme.ssh.max_output_bytes' => 300_000]);
    ($this->fakeSsh)("printf 'one\\ntwo\\n'; echo three >&2; exit 3");

    $seen = [];
    $result = ($this->client)()->run('box', RemoteScript::inline('quiet', 'true'), function (string $line, string $stream) use (&$seen) {
        $seen[] = "{$stream}:{$line}";
    });

    expect($result->exitCode)->toBe(3)
        ->and($result->stdout)->toBe("one\ntwo\n")
        ->and(trim($result->stderr))->toBe('three')
        ->and($seen)->toContain('out:one', 'out:two', 'err:three');
});

it('counts output even when nobody is listening for lines', function () {
    config(['deployme.ssh.max_output_bytes' => 200_000]);
    ($this->fakeSsh)("exec yes 'abcdefghijklmnopqrstuvwxyz'");

    expect(fn () => ($this->client)()->run('box', RemoteScript::inline('spam', 'true'), timeout: 60))
        ->toThrow(SshOutputTooLarge::class);
});

it('flushes a line that never ends once it passes 64 KB, instead of growing without bound', function () {
    $buffer = new LineBuffer;

    expect($buffer->push('out', str_repeat('a', 40_000)))->toBe([])
        ->and($buffer->push('err', str_repeat('b', 10)))->toBe([]);

    $lines = $buffer->push('out', str_repeat('a', 40_000));

    expect($lines)->toHaveCount(1)
        ->and($lines[0][0])->toBe('out')
        ->and(strlen($lines[0][1]))->toBe(80_000)
        // The partial line is empty again; only the other stream's short one is left.
        ->and($buffer->flush())->toBe([['err', str_repeat('b', 10)]]);

    // Ordinary output is unchanged.
    expect($buffer->push('out', "one\ntwo\nthr"))->toBe([['out', 'one'], ['out', 'two']])
        ->and($buffer->push('out', "ee\r"))->toBe([['out', 'three']]);
});
