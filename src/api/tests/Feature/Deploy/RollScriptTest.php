<?php

use App\Deploy\Compose;
use App\Models\Project;
use App\Platform\Ssh\RemoteScript;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

// deploy-compose-roll.sh itself, sent to bash the way ssh sends it. Its docker
// keeps each service's containers in a file under DOCKER_STATE: `ps -q` lists
// them, `up --scale` tops the list up to the wanted count with new ids (and a
// service named without --scale gets one when it has none), `stop` and `rm`
// take ids off it, `config --services` names DOCKER_SERVICES (api by default),
// and `inspect` reports every container healthy except the ids in
// DOCKER_STARTING, named after its id. A service's stopped containers are in
// <service>.stopped: `docker ps -aq` by label finds them, and, as Compose does,
// `up --scale` counts them and starts them again before it creates any.
beforeEach(function () {
    $this->dir = tempProject([
        'compose.yaml' => "services:\n  api:\n    image: demo\n",
        '.env' => "APP_KEY=x\n",
        '.deployme-pins.env' => "IMAGE_TAG=abc1234\n",
    ]);
    $this->tmp = tempProject(['bin/docker' => <<<'SH'
        #!/bin/sh
        echo "$*" >> "$DOCKER_LOG"
        last=; for a in "$@"; do last=$a; done
        case " $* " in
          *" config "*) printf '%s\n' ${DOCKER_SERVICES:-api}; exit 0 ;;
          " ps -aq "*)
            service=; for a in "$@"; do case "$a" in label=com.docker.compose.service=*) service=${a#label=com.docker.compose.service=} ;; esac; done
            cat "$DOCKER_STATE/$service.stopped" 2>/dev/null; exit 0 ;;
          *" ps -q "*) cat "$DOCKER_STATE/$last" 2>/dev/null; exit 0 ;;
          *" rename "*) exit 0 ;;
          *" up "*)
            seen=
            for arg in "$@"; do
              [ "$arg" = up ] && { seen=1; continue; }
              [ -n "$seen" ] || continue
              case "$arg" in
                --*|-d) continue ;;
                *=*)
                  service=${arg%%=*}; want=${arg##*=}
                  cat "$DOCKER_STATE/$service.stopped" >> "$DOCKER_STATE/$service" 2>/dev/null; rm -f "$DOCKER_STATE/$service.stopped"
                  have=$(grep -c . "$DOCKER_STATE/$service" 2>/dev/null || echo 0)
                  i=$have
                  while [ "$i" -lt "$want" ]; do i=$((i + 1)); echo "new-$service-$i" >> "$DOCKER_STATE/$service"; done
                  ;;
                *) grep -q . "$DOCKER_STATE/$arg" 2>/dev/null || echo "new-$arg-1" >> "$DOCKER_STATE/$arg" ;;
              esac
            done
            exit 0 ;;
          *" inspect "*)
            case " $* " in
              *".Name"*) echo "/$last" ;;
              *) case " ${DOCKER_STARTING:-} " in *" $last "*) echo starting ;; *) echo healthy ;; esac ;;
            esac
            exit 0 ;;
          *" stop "*|*" rm "*)
            for id in "$@"; do
              case "$id" in
                stop|rm|-f|--*) continue ;;
                *)
                  for file in "$DOCKER_STATE"/*; do
                    [ -f "$file" ] || continue
                    grep -vx "$id" "$file" > "$file.rest" 2>/dev/null
                    mv "$file.rest" "$file"
                  done ;;
              esac
            done
            exit 0 ;;
        esac
        exit 0
        SH]);
    chmod("{$this->tmp}/bin/docker", 0755);
    mkdir("{$this->tmp}/state");
});

/**
 * @param  list<string>  $services
 * @param  array<string, string>  $env
 */
function rollScript(string $dir, string $tmp, array $services, string $color, int $timeout = 60, array $env = []): ProcessResult
{
    $project = Project::factory()->make(['stack_name' => 'demo', 'compose_files' => ['compose.yaml']]);
    $script = RemoteScript::file('deploy-compose-roll', [
        ...Compose::vars($project, $dir, 'roll', $services),
        'TIMEOUT' => (string) $timeout,
        'COLOR' => $color,
    ]);

    return Process::env([
        'PATH' => "{$tmp}/bin:".getenv('PATH'), 'TMPDIR' => $tmp, 'DOCKER_LOG' => "{$tmp}/docker.log",
        'DOCKER_STATE' => "{$tmp}/state", ...$env,
    ])
        ->input($script->render())
        ->run(['bash', '-s']);
}

it('starts as many containers again, waits for them, retires the old ones and names the new after their colour', function () {
    file_put_contents("{$this->tmp}/state/api", "old-api-1\nold-api-2\n");

    $run = rollScript($this->dir, $this->tmp, ['api'], 'green');
    $log = (string) file_get_contents("{$this->tmp}/docker.log");

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('api: 2 running')
        ->toContain('2 green containers started beside 2 still serving')
        ->toContain('✓ new-api-3 is now api-green-1')
        ->toContain('::deployme::started=2')
        ->toContain('::deployme::retired=2')
        ->toContain('::deployme::color=green')
        ->toContain('::deployme::rolled=api')
        // Only the new pair is left, and compose was asked for twice the count.
        ->and(trim((string) file_get_contents("{$this->tmp}/state/api")))->toBe("new-api-3\nnew-api-4")
        ->and($log)->toContain('--scale api=4')
        ->toContain('rename new-api-3 api-green-1')
        ->toContain('rename new-api-4 api-green-2');
});

it('never takes a stopped container of the service for the new colour', function () {
    // One serving, and a replica that crashed (or was stopped by hand).
    file_put_contents("{$this->tmp}/state/api", "old-api-1\n");
    file_put_contents("{$this->tmp}/state/api.stopped", "old-api-2\n");

    $run = rollScript($this->dir, $this->tmp, ['api'], 'blue');

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('api: 1 running')
        ->and(trim((string) file_get_contents("{$this->tmp}/state/api")))->toBe('new-api-2')
        ->and((string) file_get_contents("{$this->tmp}/docker.log"))->not->toContain('rename old-api-2');
});

it('removes the new containers again and keeps the old ones when health never comes', function () {
    file_put_contents("{$this->tmp}/state/api", "old-api-1\n");

    $run = rollScript($this->dir, $this->tmp, ['api'], 'blue', timeout: 6, env: ['DOCKER_STARTING' => 'new-api-2']);

    expect($run->exitCode())->toBe(6)
        ->and($run->output())->toContain('new-api-2 (starting)')
        ->toContain('::deployme::started=0')
        ->and(trim((string) file_get_contents("{$this->tmp}/state/api")))->toBe('old-api-1')
        ->and((string) file_get_contents("{$this->tmp}/docker.log"))->not->toContain('rename');
});

it('starts a service nothing runs yet, one just renamed, and names its container after the colour', function () {
    $run = rollScript($this->dir, $this->tmp, ['api'], 'blue');

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('api: none running yet, so it starts fresh')
        ->toContain('✓ 1 blue containers started')
        ->toContain('::deployme::retired=0')
        ->and(trim((string) file_get_contents("{$this->tmp}/state/api")))->toBe('new-api-1')
        // One container: no number after the colour.
        ->and((string) file_get_contents("{$this->tmp}/docker.log"))->toContain('rename new-api-1 api-blue')
        ->not->toContain('--scale');
});

it('leaves a service the release doesn’t have to the recreate step', function () {
    file_put_contents("{$this->tmp}/state/api", "old-api-1\n");

    $run = rollScript($this->dir, $this->tmp, ['api'], 'green', env: ['DOCKER_SERVICES' => 'web worker']);

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain("api isn't in this release's compose file")
        ->toContain('::deployme::started=0')
        ->toContain('::deployme::rolled=')
        ->and(trim((string) file_get_contents("{$this->tmp}/state/api")))->toBe('old-api-1');
});
