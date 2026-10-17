<?php

use App\Platform\Ssh\RemoteScript;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

// deploy-health.sh run with bash in the worker, against a web server of its
// own on 127.0.0.1: the edge it asks first.
beforeEach(function () {
    $this->port = random_int(20000, 40000);
    $this->web = tempProject(['router.php' => <<<'PHP'
        <?php
        // Every request is redirected once, the way an edge sends http:// on to https://.
        if ($_SERVER['REQUEST_URI'] === '/up') {
            header('Location: http://demo.test:'.$_SERVER['SERVER_PORT'].'/app/up', true, 308);
            return;
        }
        http_response_code((int) (getenv('APP_STATUS') ?: 200));
        PHP]);
});

afterEach(function () {
    $this->server?->stop();
});

function healthOf(object $test, int $appStatus): ProcessResult
{
    $test->server = Process::env(['APP_STATUS' => (string) $appStatus])
        ->path($test->web)->start(['php', '-S', "127.0.0.1:{$test->port}", 'router.php']);
    usleep(300_000);

    return Process::input(RemoteScript::file('deploy-health', [
        'URL' => "http://demo.test:{$test->port}/up", 'TIMEOUT' => 0, 'INTERVAL' => 0,
    ])->render())->run(['bash', '-s']);
}

it('follows a redirect to the app, and fails when the app behind it doesn’t answer', function () {
    $down = healthOf($this, 502);

    expect($down->exitCode())->toBe(30, $down->output())
        ->and($down->output())->toContain('::deployme::last=HTTP 502');
})->skip(fn () => Process::run(['sh', '-c', 'command -v curl'])->failed(), 'needs curl');

it('passes once the app behind the redirect answers', function () {
    $up = healthOf($this, 200);

    expect($up->exitCode())->toBe(0, $up->output())
        ->and($up->output())->toContain('::deployme::code=200')
        ->toContain('::deployme::via=edge');
})->skip(fn () => Process::run(['sh', '-c', 'command -v curl'])->failed(), 'needs curl');
