<?php

use App\Platform\Ssh\RemoteScript;
use Illuminate\Support\Facades\Process;

// deploy-compose-health.sh run with bash in the worker, against a docker that
// answers the way Compose does for a stack with a `compose run` left behind.
it('doesn’t count a one-off container left behind as the service being down', function () {
    $tmp = tempProject([
        'release/compose.yaml' => "services: {}\n",
        // ps --all lists the one-off under its service's name.
        'bin/docker' => <<<'SH'
            #!/bin/sh
            case "$*" in
              *"compose "*" ps --all"*"{{.Name}}"*) printf 'app\trunning\t-\tdemo-app-1\napp\texited\t-\tdemo-app-run-3f2a\n' ;;
              *"compose "*" ps --all"*) printf 'app\trunning\t\napp\texited\t\n' ;;
              "ps -a "*"com.docker.compose.oneoff=True"*) printf 'demo-app-run-3f2a\n' ;;
              *) exit 1 ;;
            esac
            SH,
    ]);
    chmod("{$tmp}/bin/docker", 0755);

    $health = fn () => Process::env(['PATH' => "{$tmp}/bin:".getenv('PATH')])
        ->input(RemoteScript::file('deploy-compose-health', [
            'DIR' => "{$tmp}/release", 'STACK' => 'demo', 'FILES' => 'compose.yaml', 'SERVICES' => 'app', 'TIMEOUT' => 0, 'INTERVAL' => 0,
        ])->render())
        ->run(['bash', '-s']);

    $run = $health();
    expect($run->exitCode())->toBe(0, $run->output())
        ->and($run->output())->toContain('✓ Everything is up');

    // The service's own container exited: that still fails.
    file_put_contents("{$tmp}/bin/docker", str_replace("app\\trunning\\t-\\tdemo-app-1", "app\\texited\\t-\\tdemo-app-1", file_get_contents("{$tmp}/bin/docker")));
    $run = $health();
    expect($run->exitCode())->toBe(30)
        ->and($run->output())->toContain('::deployme::waiting=app (exited)');
});
