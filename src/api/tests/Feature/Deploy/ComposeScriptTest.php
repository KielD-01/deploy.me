<?php

use App\Deploy\Compose;
use App\Deploy\Pipeline\Steps\RunMigrations;
use App\Models\Project;
use App\Platform\Ssh\RemoteScript;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

// deploy-compose.sh itself, sent to bash the way ssh sends it, in a release
// with both env files. Its docker only logs each call, followed by the compose
// files it gets from outside the release (lines prefixed "  | "), and exits
// with DOCKER_EXIT. `ps -aq` lists a service's containers from
// DOCKER_STATE/<service>, and a plain `up` gives the services in
// DOCKER_UP_REPLACES new ones. `config --services` names DOCKER_SERVICES (app
// when unset), and `ps -a` prints DOCKER_CONTAINERS: the stack's containers,
// "id service oneoff name" per line.
beforeEach(function () {
    $this->dir = tempProject([
        'compose.yaml' => "services:\n  app:\n    image: demo\n    labels:\n      caddy: demo.test\n",
        '.env' => "APP_KEY=x\n",
        '.deployme-pins.env' => "IMAGE_TAG=abc1234\n",
    ]);
    $this->tmp = tempProject(['bin/docker' => <<<'SH'
        #!/bin/sh
        echo "$*" >> "$DOCKER_LOG"
        while [ $# -gt 0 ]; do
          case "$1" in
            -f) case "$2" in /*) sed 's/^/  | /' "$2" >> "$DOCKER_LOG" ;; esac; shift 2 ;;
            ps)
              [ "$2" = -aq ] && { cat "$DOCKER_STATE/$3" 2>/dev/null; exit 0; }
              [ "$2" = -a ] && printf '%b' "${DOCKER_CONTAINERS:-}"
              exit 0 ;;
            config) printf '%s\n' ${DOCKER_SERVICES-app}; exit 0 ;;
            up)
              case "$*" in
                *--force-recreate*) ;;
                *) for service in ${DOCKER_UP_REPLACES:-}; do echo "new-$service" > "$DOCKER_STATE/$service"; done ;;
              esac
              exit "${DOCKER_EXIT:-0}"
              ;;
            *) shift ;;
          esac
        done
        exit "${DOCKER_EXIT:-0}"
        SH]);
    chmod("{$this->tmp}/bin/docker", 0755);
    mkdir("{$this->tmp}/state");
});

/**
 * @param  list<string>  $services
 * @param  array<string, string>  $env
 */
function composeScript(string $dir, string $tmp, string $action, array $services, string $command = '', int $exit = 0, array $env = []): ProcessResult
{
    $project = Project::factory()->make(['stack_name' => 'demo', 'compose_files' => ['compose.yaml']]);
    $script = RemoteScript::file('deploy-compose', Compose::vars($project, $dir, $action, $services, $command));

    return Process::env([
        'PATH' => "{$tmp}/bin:".getenv('PATH'), 'TMPDIR' => $tmp, 'DOCKER_LOG' => "{$tmp}/docker.log",
        'DOCKER_STATE' => "{$tmp}/state", 'DOCKER_EXIT' => (string) $exit, ...$env,
    ])
        ->input($script->render())
        ->run(['bash', '-s']);
}

/**
 * The `up` calls deploy-compose.sh made, without the arguments every call has.
 *
 * @return list<string>
 */
function composeUps(string $tmp): array
{
    return array_values(array_map(
        fn (string $line) => Str::after($line, '.deployme-pins.env '),
        preg_grep('/ up -d/', file("{$tmp}/docker.log", FILE_IGNORE_NEW_LINES)),
    ));
}

it('runs a one-off without the service’s labels, from a compose file it removes afterwards', function () {
    $run = composeScript($this->dir, $this->tmp, 'run', ['app'], RunMigrations::COMMAND);
    $log = (string) file_get_contents("{$this->tmp}/docker.log");

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toStartWith("\$ docker compose -p demo run --rm --no-deps app php artisan migrate --force\n")
        // The release's env files still apply, and the reset is the last compose file.
        ->and(preg_replace('~/deployme-run\.\w+ ~', '/deployme-run.XXXXXX ', $log))->toBe(
            "compose --ansi never -p demo --project-directory {$this->dir} -f compose.yaml --env-file {$this->dir}/.env --env-file {$this->dir}/.deployme-pins.env"
            ." -f {$this->tmp}/deployme-run.XXXXXX run --rm --no-deps -T app sh -c php artisan migrate --force\n"
            ."  | services:\n  |   'app':\n  |     labels: !reset {}\n  |     label_file: !reset []\n"
        )
        ->and(glob("{$this->tmp}/deployme-run.*"))->toBe([]);
});

it('passes the one-off’s exit code on, and removes the compose file after a failure too', function () {
    $run = composeScript($this->dir, $this->tmp, 'run', ['app'], RunMigrations::COMMAND, exit: 3);

    expect($run->exitCode())->toBe(3)
        ->and(glob("{$this->tmp}/deployme-run.*"))->toBe([]);
});

it('won’t run in a service Compose couldn’t name', function () {
    $run = composeScript($this->dir, $this->tmp, 'run', ["app': {privileged: true}, 'x"], 'id');

    expect($run->exitCode())->toBe(2)
        ->and($run->output())->toBe("✗ app': {privileged: true}, 'x isn't a Compose service name\n")
        ->and(file_exists("{$this->tmp}/docker.log"))->toBeFalse();
});

it('leaves the labels alone for the services it recreates or execs into', function () {
    composeScript($this->dir, $this->tmp, 'recreate', ['app']);
    composeScript($this->dir, $this->tmp, 'exec', ['app'], 'php artisan about');

    expect((string) file_get_contents("{$this->tmp}/docker.log"))
        ->toContain('up -d --force-recreate --no-deps app')
        ->toContain('exec -T app sh -c php artisan about')
        ->not->toContain('deployme-run');
});

it('recreates the app services up left alone, and not the ones it just replaced', function () {
    file_put_contents("{$this->tmp}/state/api", "old-api\n");
    file_put_contents("{$this->tmp}/state/worker", "old-worker\n");

    // A new image for api, so up replaces it; worker's is the same.
    $run = composeScript($this->dir, $this->tmp, 'recreate', ['api', 'worker'], env: ['DOCKER_UP_REPLACES' => 'api']);

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and(composeUps($this->tmp))->toBe(['up -d', 'up -d --force-recreate --no-deps worker']);
});

it('recreates nothing twice when up replaced every app service, or started it', function () {
    file_put_contents("{$this->tmp}/state/api", "old-api\n");

    // api had a container and gets a new one; worker had none (a first deploy).
    $run = composeScript($this->dir, $this->tmp, 'recreate', ['api', 'worker'], env: ['DOCKER_UP_REPLACES' => 'api worker']);

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and(composeUps($this->tmp))->toBe(['up -d'])
        ->and($run->output())->toEndWith("✓ up -d replaced api worker\n");
});

it('retires the containers of a service the release no longer has, once its own are up', function () {
    $run = composeScript($this->dir, $this->tmp, 'recreate', ['api', 'worker'], env: [
        'DOCKER_UP_REPLACES' => 'api worker',
        // Profiles included, so migrate is a service of the release.
        'DOCKER_SERVICES' => 'api worker migrate',
        // web was renamed away. Its one-off stays: one-offs are never touched.
        'DOCKER_CONTAINERS' => 'c1 api False demo-api-1\nc2 web False demo-web-1\nc3 worker False demo-worker-1\nc4 web True demo-web-run-1a2b\nc5 migrate False demo-migrate-1\n',
    ]);
    $log = (string) file_get_contents("{$this->tmp}/docker.log");

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toEndWith("✓ Retired demo-web-1: their services aren't in this release\n")
        ->and($log)->toContain("stop c2\n")->toContain("rm -f c2\n")
        ->not->toContain('stop c1')->not->toContain('stop c3')->not->toContain('stop c4')->not->toContain('stop c5');
});

it('retires nothing when Compose can’t say which services the release has', function () {
    $run = composeScript($this->dir, $this->tmp, 'recreate', ['api'], env: [
        'DOCKER_UP_REPLACES' => 'api',
        'DOCKER_SERVICES' => '',
        'DOCKER_CONTAINERS' => 'c1 web False demo-web-1\n',
    ]);

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and((string) file_get_contents("{$this->tmp}/docker.log"))->not->toContain('ps -a --filter')->not->toContain('stop c1');
});
