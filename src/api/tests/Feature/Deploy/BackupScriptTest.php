<?php

use App\Platform\Ssh\RemoteScript;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

// deploy-db-backup.sh itself, sent to bash the way ssh sends it. Its docker
// knows one container, DOCKER_CONTAINER, answering to the aliases in
// DOCKER_ALIASES, and writes a few bytes for any pg_dump.
beforeEach(function () {
    $this->tmp = tempProject(['bin/docker' => <<<'SH'
        #!/bin/sh
        echo "$*" >> "$DOCKER_LOG"
        last=; for a in "$@"; do last=$a; done
        case " $* " in
          *" inspect --type container "*)
            [ "$last" = "$DOCKER_CONTAINER" ] && exit 0
            exit 1 ;;
          *" ps --format "*) printf '%s\n' ${DOCKER_RUNNING:-} | tr ' ' '\n'; exit 0 ;;
          *" inspect -f "*)
            # DOCKER_PAIRS: container=network:alias, space separated
            for pair in ${DOCKER_PAIRS:-}; do
              [ "${pair%%=*}" = "$last" ] || continue
              rest=${pair#*=}; echo "${rest%%:*} ${rest#*:}"
            done
            exit 0 ;;
          *pg_dump*) printf 'PGDMP a small dump'; exit 0 ;;
        esac
        exit 0
        SH]);
    chmod("{$this->tmp}/bin/docker", 0755);
});

/**
 * @param  array<string, string>  $vars
 */
function backupScript(string $tmp, array $vars = [], string $running = 'other-1 infra-postgres-1', string $pairs = 'infra-postgres-1=proxy-network:postgres infra-postgres-1=proxy-network:infra-postgres-1'): ProcessResult
{
    $script = RemoteScript::file('deploy-db-backup', [
        'CONTAINER' => 'postgres', 'DATABASE' => 'demo', 'DB_USER' => 'postgres', 'DB_PASSWORD' => '',
        'DIR' => "{$tmp}/backups", 'KEEP' => '3', ...$vars,
    ]);

    return Process::env([
        'PATH' => "{$tmp}/bin:".getenv('PATH'), 'TMPDIR' => $tmp, 'DOCKER_LOG' => "{$tmp}/docker.log",
        'DOCKER_CONTAINER' => 'infra-postgres-1', 'DOCKER_RUNNING' => $running, 'DOCKER_PAIRS' => $pairs,
    ])
        ->input($script->render())
        ->run(['bash', '-s']);
}

it('dumps through the container behind the host name, which is only an alias', function () {
    $run = backupScript($this->tmp);

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('· postgres is the container infra-postgres-1')
        ->toContain('::deployme::size=18')
        ->and((string) file_get_contents("{$this->tmp}/docker.log"))
        ->toContain('exec -e PGPASSWORD infra-postgres-1 pg_dump -Fc -w -U postgres demo')
        ->and(glob("{$this->tmp}/backups/demo-*.dump"))->toHaveCount(1);
});

it('says so when nothing answers to that name', function () {
    $run = backupScript($this->tmp, running: 'other-1');

    expect($run->exitCode())->toBe(42)
        ->and($run->output())->toContain('No running container is named or aliased postgres')
        ->and(glob("{$this->tmp}/backups/*.dump"))->toBe([]);
});

it('keeps the newest dumps and removes the rest', function () {
    mkdir("{$this->tmp}/backups");
    foreach (['20260901-120000', '20260902-120000', '20260903-120000'] as $old) {
        file_put_contents("{$this->tmp}/backups/demo-{$old}.dump", 'old');
    }

    $run = backupScript($this->tmp, ['KEEP' => '2']);

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('· Removed demo-20260901-120000.dump')
        ->and(glob("{$this->tmp}/backups/demo-*.dump"))->toHaveCount(2);
});

it('takes the alias on the stack’s own network, not another project’s database of the same name', function () {
    $run = backupScript($this->tmp, ['NETWORKS' => 'proxy-network'], running: 'school-app-postgres-1 infra-postgres-1',
        pairs: 'school-app-postgres-1=school-app_default:postgres infra-postgres-1=proxy-network:postgres');

    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('· postgres is the container infra-postgres-1')
        ->and((string) file_get_contents("{$this->tmp}/docker.log"))->toContain('exec -e PGPASSWORD infra-postgres-1 pg_dump');
});

it('names the containers that answer to it only on other networks', function () {
    $run = backupScript($this->tmp, ['NETWORKS' => 'proxy-network'], running: 'school-app-postgres-1',
        pairs: 'school-app-postgres-1=school-app_default:postgres');

    expect($run->exitCode())->toBe(42)
        ->and($run->output())->toContain('No running container is named or aliased postgres on proxy-network')
        ->toContain('school-app-postgres-1 on school-app_default');
});

it('refuses to guess between two containers on the stack’s network', function () {
    $run = backupScript($this->tmp, ['NETWORKS' => 'proxy-network'], running: 'a-1 b-1',
        pairs: 'a-1=proxy-network:postgres b-1=proxy-network:postgres');

    expect($run->exitCode())->toBe(43)
        ->and($run->output())->toContain('More than one container answers to postgres: a-1 b-1');
});
