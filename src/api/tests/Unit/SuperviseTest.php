<?php

use Symfony\Component\Process\Process;

// deployme-supervise runs the worker container's queue workers and scheduler
// (core/local/php/bin, baked into the image as /usr/local/bin). These tests run
// it as it runs there: a process of its own, with short real programs.

function superviseRepo(string $path): string
{
    return dirname(__DIR__, 4).'/core/local/php/'.$path;
}

function superviseScript(): string
{
    foreach ([superviseRepo('bin/deployme-supervise'), '/usr/local/bin/deployme-supervise'] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    throw new RuntimeException('deployme-supervise not found');
}

/** The image's program files: the checkout's, or the ones baked into the image. */
function superviseProgramsDir(): string
{
    foreach ([superviseRepo('supervisor/conf.d'), '/etc/supervisor/conf.d'] as $path) {
        if (is_dir($path)) {
            return $path;
        }
    }

    throw new RuntimeException('supervisor/conf.d not found');
}

/**
 * @param  list<string>  $args
 */
function supervise(string $dir, array $args): Process
{
    return new Process([PHP_BINARY, superviseScript(), ...$args], null, ['DEPLOYME_SUPERVISE_STATE' => "{$dir}/state.json"]);
}

function superviseConf(string $dir, string $conf): string
{
    file_put_contents("{$dir}/programs.conf", $conf);

    return $dir;
}

/** @return array<string, mixed>|null */
function superviseState(string $dir): ?array
{
    $file = "{$dir}/state.json";

    return is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
}

function superviseWait(Closure $until, float $seconds = 8.0): void
{
    $deadline = microtime(true) + $seconds;
    while (! $until()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('timed out waiting');
        }
        usleep(50_000);
    }
}

function superviseAllRunning(string $dir): bool
{
    $state = superviseState($dir);

    return $state !== null && collect($state['processes'])->every(fn (array $p) => $p['state'] === 'RUNNING');
}

beforeEach(function () {
    if (! function_exists('pcntl_async_signals') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('needs the pcntl and posix extensions, like the image has');
    }

    $this->dir = sys_get_temp_dir().'/deployme-supervise-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    foreach ($this->running ?? [] as $process) {
        $process->stop(1);
    }
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->dir);
});

it('reads supervisord program files: process names, numprocs and the command split like a shell would', function () {
    $dir = superviseConf($this->dir, <<<'CONF'
        ; a comment
        [program:queue]
        command=php artisan queue:work --name="the queue" 'two words' plain\ space ; trailing comment
        process_name=%(program_name)s_%(process_num)02d
        numprocs=2
        stopsignal=QUIT
        stopwaitsecs=120
        stopasgroup=true
        redirect_stderr=true
        stdout_logfile=/dev/stdout
        stdout_logfile_maxbytes=0

        [program:solo]
        command=sleep 1
        autorestart=unexpected
        exitcodes=0,2
        CONF);

    $process = supervise($this->dir, ['config', $dir]);
    $process->mustRun();
    $programs = collect(json_decode($process->getOutput(), true))->keyBy('name');

    expect($programs->keys()->all())->toBe(['queue', 'solo'])
        ->and(array_column($programs['queue']['processes'], 'name'))->toBe(['queue_00', 'queue_01'])
        ->and($programs['queue']['processes'][1]['argv'])->toBe(['php', 'artisan', 'queue:work', '--name=the queue', 'two words', 'plain space'])
        ->and($programs['queue'])->toMatchArray(['stopsignal' => 3, 'stopwaitsecs' => 120, 'stopasgroup' => true, 'killasgroup' => false, 'redirectStderr' => true])
        // supervisord's defaults where the file says nothing.
        ->and($programs['solo'])->toMatchArray(['numprocs' => 1, 'autostart' => true, 'autorestart' => 'unexpected', 'exitcodes' => [0, 2], 'startsecs' => 1, 'stopsignal' => 15, 'stopwaitsecs' => 10])
        ->and($programs['solo']['processes'][0]['name'])->toBe('solo');
});

it('refuses what it doesn’t know, rather than run a worker differently', function (string $conf, string $error) {
    $process = supervise($this->dir, ['config', superviseConf($this->dir, $conf)]);
    $process->run();

    expect($process->getExitCode())->toBe(78)
        ->and($process->getErrorOutput())->toContain($error);
})->with([
    'a misspelt key' => ["[program:a]\ncommand=sleep 1\nnumproc=2\n", 'numproc: unknown key'],
    'a log file docker doesn’t read' => ["[program:a]\ncommand=sleep 1\nstdout_logfile=/var/log/a.log\n", 'only /dev/stdout'],
    'several processes with one name' => ["[program:a]\ncommand=sleep 1\nnumprocs=2\n", 'needs %(process_num)'],
    'another section' => ["[supervisord]\nnodaemon=true\n", 'only [program:<name>] sections'],
    'an unclosed quote' => ["[program:a]\ncommand=sh -c 'echo\n", 'unclosed'],
]);

it('reads the image’s own programs: every queue worker and the scheduler, inside compose’s stop grace period', function () {
    $process = supervise($this->dir, ['config', superviseProgramsDir()]);
    $process->mustRun();
    $programs = collect(json_decode($process->getOutput(), true))->keyBy('name');

    expect($programs->keys()->sort()->values()->all())->toBe(['default', 'deploys', 'probes', 'scheduler', 'syncs'])
        ->and($programs->every(fn (array $p) => $p['stopasgroup'] && $p['killasgroup'] && $p['autorestart'] === 'true'))->toBeTrue();

    // docker stop waits stop_grace_period before it kills the container: longer
    // than any program's stopwaitsecs, so a worker can finish its job.
    $compose = dirname(__DIR__, 4).'/docker-compose.local.yml';
    if (is_readable($compose) && preg_match('/deployme-worker:.*?stop_grace_period: (\d+)s/s', (string) file_get_contents($compose), $grace)) {
        expect((int) $grace[1])->toBeGreaterThan($programs->max('stopwaitsecs'));
    }
});

it('starts a process again: at once after a run, after a growing wait when it doesn’t start', function () {
    $dir = superviseConf($this->dir, <<<'CONF'
        [program:steady]
        command=sh -c 'echo run; sleep 1.2'
        startsecs=1

        [program:broken]
        command=sh -c 'echo nope >&2; exit 3'
        startsecs=1
        CONF);

    $this->running[] = $supervisor = supervise($this->dir, ['run', $dir]);
    $supervisor->start();

    superviseWait(fn () => str_contains($supervisor->getOutput(), 'trying again in 2s') && substr_count($supervisor->getOutput(), 'steady | run') >= 2);

    $status = supervise($this->dir, ['status']);
    $status->run();

    expect($supervisor->getOutput())
        ->toMatch('/steady exited with 0 after 1\.\ds; starting it again/')
        ->toMatch("/broken didn't start \\(exited with 3 after 0\\.\\ds\\); trying again in 1s/")
        ->and($supervisor->getErrorOutput())->toContain('broken | nope')
        // One that doesn't start isn't running.
        ->and($status->getExitCode())->toBe(1)
        ->and($status->getOutput())->toMatch('/^broken\s+BACKOFF\s+exited with 3/m');
});

it('stops gracefully: the stop signal first, SIGKILL for what is still there after stopwaitsecs', function () {
    $dir = superviseConf($this->dir, <<<CONF
        [program:polite]
        command=sh -c 'trap "echo bye; exit 0" TERM; while :; do sleep 0.1; done'
        startsecs=0
        stopasgroup=true
        killasgroup=true

        [program:stubborn]
        command=sh -c 'trap "" TERM; echo \$\$ > {$this->dir}/stubborn.pid; while :; do sleep 0.1; done'
        startsecs=0
        stopwaitsecs=1
        stopasgroup=true
        killasgroup=true
        CONF);

    $this->running[] = $supervisor = supervise($this->dir, ['run', $dir]);
    $supervisor->start();
    superviseWait(fn () => superviseAllRunning($this->dir) && is_file("{$this->dir}/stubborn.pid"));

    $status = supervise($this->dir, ['status']);
    $status->run();
    expect($status->getExitCode())->toBe(0)
        ->and($status->getOutput())->toMatch('/^polite\s+RUNNING\s+pid \d+/m');

    $started = microtime(true);
    $supervisor->signal(SIGTERM);
    $supervisor->wait();
    $took = microtime(true) - $started;
    $pid = (int) file_get_contents("{$this->dir}/stubborn.pid");

    expect($supervisor->getExitCode())->toBe(0)
        ->and($supervisor->getOutput())
        ->toContain('polite | bye')
        ->toContain('polite stopped: exited with 0')
        ->toContain("stubborn didn't stop in 1s: sent SIGKILL")
        ->toContain('stubborn stopped: killed by SIGKILL')
        ->toContain('every program has stopped')
        ->and($took)->toBeGreaterThan(0.9)->toBeLessThan(5.0)
        // Its whole group went, not just the shell.
        ->and(posix_kill($pid, 0))->toBeFalse();
});

it('restarts every program, each after it stopped, when asked', function () {
    $dir = superviseConf($this->dir, <<<'CONF'
        [program:worker]
        command=sh -c 'echo up; trap "exit 0" TERM; while :; do sleep 0.1; done'
        startsecs=0
        stopasgroup=true
        CONF);

    $this->running[] = $supervisor = supervise($this->dir, ['run', $dir]);
    $supervisor->start();
    superviseWait(fn () => superviseAllRunning($this->dir));

    $restart = supervise($this->dir, ['restart']);
    $restart->mustRun();
    superviseWait(fn () => substr_count($supervisor->getOutput(), 'worker | up') === 2 && superviseAllRunning($this->dir));

    expect($supervisor->getOutput())->toContain('worker stopped for a restart: exited with 0')
        ->and(superviseState($this->dir)['processes'][0]['restarts'])->toBe(1);

    $supervisor->signal(SIGTERM);
    $supervisor->wait();
    expect($supervisor->getExitCode())->toBe(0);
});

it('says it isn’t running when there is no supervisor', function () {
    $status = supervise($this->dir, ['status']);
    $status->run();

    expect($status->getExitCode())->toBe(2)
        ->and($status->getErrorOutput())->toContain("deployme-supervise isn't running");
});
