<?php

use App\Platform\Ssh\RemoteScript;
use Symfony\Component\Process\Process;

it('delivers variables unchanged: quotes, dollars and newlines', function () {
    $value = "it's \"quoted\" \$HOME `whoami` \nsecond line";
    $script = RemoteScript::inline('echo', 'printf "%s" "$VALUE"', ['VALUE' => $value]);

    $process = new Process(['bash', '-s']);
    $process->setInput($script->render());
    $process->mustRun();

    expect($process->getOutput())->toBe($value);
});

it('keeps trailing newlines, under sh too', function (string $shell) {
    $value = "APP_NAME=demo\nAPP_KEY=base64:abc\n\n";
    $script = RemoteScript::inline('echo', 'printf "%s" "$VALUE"', ['VALUE' => $value], $shell);

    $process = new Process([$shell, '-s']);
    $process->setInput($script->render());
    $process->mustRun();

    expect($process->getOutput())->toBe($value);
})->with(['bash', 'sh']);

it('never puts values on the command line in plain text', function () {
    $render = RemoteScript::inline('x', 'true', ['SECRET' => 'hunter2'])->render();

    expect($render)->not->toContain('hunter2')->toContain(base64_encode('hunter2'));
});

it('runs every script but a deploy script the way it always did', function () {
    expect(RemoteScript::inline('x', 'true')->command())->toBe('bash -s')
        ->and(RemoteScript::inline('x', 'true', [], 'sh')->command())->toBe('sh -s')
        ->and(RemoteScript::inline('x', 'true', ['A' => 1])->render())->not->toContain('PGID_FILE');
});

it('runs a deploy script as the leader of its own session, noting its process group while it runs', function () {
    $runs = sys_get_temp_dir().'/deployme-runs-'.bin2hex(random_bytes(4));
    mkdir($runs);
    $script = RemoteScript::inline('x', 'read -r _ _ _ _ group _ < /proc/$$/stat; echo "$$ $group $(cat "$PGID_FILE")"', ['PGID_FILE' => "{$runs}/7.pgid"])->inOwnSession();

    $process = Process::fromShellCommandline($script->command());
    $process->setInput($script->render());
    $process->mustRun();
    [$pid, $group, $noted] = explode(' ', trim($process->getOutput()));

    expect($script->command())->toContain('setsid --wait bash -s')
        ->and($group)->toBe($pid)
        ->and($noted)->toBe($pid)
        // Gone with the script.
        ->and(file_exists("{$runs}/7.pgid"))->toBeFalse();
});

it('runs a deploy script anyway where it can’t note its process group, or setsid can’t wait', function () {
    $bin = sys_get_temp_dir().'/deployme-nosetsid-'.bin2hex(random_bytes(4));
    mkdir($bin);
    file_put_contents("{$bin}/setsid", "#!/bin/sh\necho \"setsid: unrecognized option '--wait'\" >&2\nexit 1\n");
    chmod("{$bin}/setsid", 0755);
    $script = RemoteScript::inline('x', 'echo "ran with $A"', ['A' => 'it', 'PGID_FILE' => '/nonexistent/runs/7.pgid'])->inOwnSession();

    $process = Process::fromShellCommandline($script->command(), env: ['PATH' => "{$bin}:".getenv('PATH')]);
    $process->setInput($script->render());
    $process->mustRun();

    expect($process->getOutput())->toBe("ran with it\n");
});

it('refuses variable names that are not shell-safe', function () {
    RemoteScript::inline('x', 'true', ['bad-name' => 'x']);
})->throws(InvalidArgumentException::class);

it('only runs through bash or sh', function () {
    RemoteScript::inline('x', 'true', [], 'zsh');
})->throws(InvalidArgumentException::class);
