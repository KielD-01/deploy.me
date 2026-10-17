<?php

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Server;
use App\Models\Target;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;
use Illuminate\Support\Facades\Event;

// Docker deploys against the local sandbox (`make sandbox`), whose docker is
// a shim: it runs nothing, logs every call to /tmp/docker-calls.log, and
// takes cues from files in the release (.sandbox-unhealthy,
// .sandbox-migrations). Skipped when the sandbox isn't running.
beforeEach(function () {
    if (gethostbyname('deployme-sshd-target') === 'deployme-sshd-target' || ! is_readable(storage_path('app/sandbox-ssh/ssh_config'))) {
        $this->markTestSkipped('The sandbox isn’t running: `make sandbox`.');
    }

    config([
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-it-'.bin2hex(random_bytes(4)),
        'deployme.ssh.user_known_hosts' => '/nonexistent',
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 50,
    ]);

    $this->app->singleton(SshConfigCompiler::class, fn () => SshConfigCompiler::fromConfig());
    $this->app->singleton(KnownHosts::class);
    $this->app->singleton(SshClient::class, ProcessSshClient::class);
    app(KnownHosts::class)->trust(app(KnownHosts::class)->scan('sandbox'), null);

    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
    $this->slug = 'it-docker-'.bin2hex(random_bytes(3));
    sandboxShell(': > /tmp/docker-calls.log');
});

afterEach(function () {
    if (isset($this->slug)) {
        sandboxShell('rm -rf -- "$DIR"', ['DIR' => "/opt/srv/{$this->slug}"]);
    }
});

function sandboxShell(string $script, array $vars = []): string
{
    return app(SshClient::class)->run('sandbox', RemoteScript::inline('shell', $script, $vars))->throw()->stdout;
}

function sandboxDockerTarget(string $slug, array $files = [], array $project = []): Target
{
    $repo = tempGitRepo([
        'compose.yaml' => "services:\n  app:\n    build: .\n",
        'Dockerfile' => "FROM php:8.5-cli\n",
        'public/index.php' => '<?php echo "v1";',
        ...$files,
    ]);

    $server = Server::factory()->create(['ssh_alias' => 'sandbox', 'default_deploy_path' => '/opt/srv']);
    $project = Project::factory()->create([
        'slug' => $slug, 'name' => $slug,
        'source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null,
        'dockerized' => true, 'stack_name' => str_replace('-', '_', $slug), 'compose_files' => ['compose.yaml'],
        'app_services' => ['app'], 'migrate_service' => 'app', 'shared_paths' => [], 'health_timeout' => 10,
        'delivery_strategies' => [['key' => 'push_archive', 'enabled' => true]],
        ...$project,
    ]);

    return Target::factory()->create(['server_id' => $server->id, 'project_id' => $project->id, 'base_dir' => '/opt/srv']);
}

function sandboxDockerRun(Target $target, DeploymentKind $kind = DeploymentKind::Deploy, array $options = []): Deployment
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => $kind, 'options' => $options]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

it('deploys a Docker project on a real server: build, migrate, recreate, health', function () {
    $target = sandboxDockerTarget($this->slug, ['.sandbox-migrations' => "  2026_09_11_000000_create_orders_table .......... 12ms DONE\n  2026_09_11_000001_add_order_totals ............. 3ms DONE\n"]);

    $deployment = sandboxDockerRun($target);
    $release = $deployment->release;
    $calls = sandboxShell('cat /tmp/docker-calls.log');
    $prefix = "compose --ansi never -p {$target->project->stack_name} --project-directory {$release->path} -f compose.yaml";

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and($release->migrations)->toBe(2)
        ->and($calls)->toContain("{$prefix} build")
        ->toContain("{$prefix} up -d --force-recreate --no-deps app")
        ->toContain("{$prefix} ps --all --format");

    // The migrations ran in a one-off without the service's labels (the edge
    // routes by them): a last compose file resets them, gone after the run.
    expect(preg_match('~^'.preg_quote($prefix, '~').' -f (/tmp/deployme-run\.\w+) run --rm --no-deps -T app sh -c php artisan migrate --force\n((?:  \| .*\n)+)~m', $calls, $run))->toBe(1, $calls)
        ->and($run[2])->toBe("  | services:\n  |   'app':\n  |     labels: !reset {}\n  |     label_file: !reset []\n")
        ->and(sandboxShell('[ -e "$F" ] && echo left || echo gone', ['F' => $run[1]]))->toBe("gone\n");
})->group('ssh-integration');

it('switches back on a real server when the Docker services stay unhealthy', function () {
    $target = sandboxDockerTarget($this->slug);
    $good = sandboxDockerRun($target);
    expect($good->status)->toBe(DeploymentStatus::Succeeded, (string) $good->outcome);

    commitFile($target->project->local_path, '.sandbox-unhealthy', 'yes');
    $bad = sandboxDockerRun($target);
    $calls = sandboxShell('cat /tmp/docker-calls.log');

    expect($bad->status)->toBe(DeploymentStatus::RolledBack, (string) $bad->outcome)
        ->and($bad->outcome)->toContain('app (running, unhealthy)')
        ->and(trim(sandboxShell('readlink "$DIR/current"', ['DIR' => "/opt/srv/{$this->slug}"])))->toBe("releases/{$good->release->name}")
        // The rollback recreated the services from the good release, last.
        ->and(strrpos($calls, "--project-directory {$good->release->path} -f compose.yaml up -d --force-recreate"))
        ->toBeGreaterThan(strrpos($calls, "--project-directory {$bad->release->path} -f compose.yaml up -d --force-recreate"));
})->group('ssh-integration');

it('adopts a git checkout on a real server, with the tags it runs and what it deployed before', function () {
    $target = sandboxDockerTarget($this->slug, project: [
        'versionable' => false,
        'detection' => ['compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'sandbox-app']]]]],
    ]);
    sandboxShell(<<<'SH'
        mkdir -p -- "$DIR" && cd -- "$DIR"
        git init -q && printf 'IMAGE_TAG=9e1f2aa\n' > .env && printf 'hello' > index.html
        printf 'CURRENT_SHA=%s\nPREVIOUS_SHA=%s\n' "$(printf a%.0s $(seq 40))" "$(printf b%.0s $(seq 40))" > .deploy-state
        git add index.html && git -c user.name=Test -c user.email=test@example.com commit -qm "The site as it was"
        SH, ['DIR' => "/opt/srv/{$this->slug}"]);
    $sha = trim(sandboxShell('git -C "$DIR" rev-parse HEAD', ['DIR' => "/opt/srv/{$this->slug}"]));

    $adopt = sandboxDockerRun($target, DeploymentKind::Adopt);

    // git wins over .deploy-state for the commit; the one before it is only said.
    expect($adopt->status)->toBe(DeploymentStatus::Succeeded, (string) $adopt->outcome)
        ->and($adopt->release->commit_sha)->toBe($sha)
        ->and($adopt->release->commit_message)->toBe('The site as it was')
        ->and($adopt->release->adopted)->toBeTrue()
        ->and($adopt->release->image_pins)->toBe(['IMAGE_TAG' => '9e1f2aa'])
        ->and($adopt->logLines()->pluck('line')->implode("\n"))->toContain('The deployment before this one was bbbbbbb')
        ->and(sandboxShell('cat "$DIR/index.html"; grep -c adopted "$DIR/.deploy/state.json"', ['DIR' => "/opt/srv/{$this->slug}"]))->toBe("hello1\n");
})->group('ssh-integration');

it('removes the images an old release pinned, on a real server', function () {
    $target = sandboxDockerTarget(
        $this->slug,
        ['compose.yaml' => "services:\n  app:\n    build: .\n    image: sandbox-app:\${IMAGE_TAG}\n"],
        [
            'keep_releases' => 2,
            'detection' => ['compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'sandbox-app']]]]],
        ],
    );

    $first = sandboxDockerRun($target)->release;
    commitFile($target->project->local_path, 'public/index.php', '<?php echo "v2";');
    $second = sandboxDockerRun($target)->release;
    commitFile($target->project->local_path, 'public/index.php', '<?php echo "v3";');
    $third = sandboxDockerRun($target);
    $calls = sandboxShell('cat /tmp/docker-calls.log');

    expect($third->status)->toBe(DeploymentStatus::Succeeded, (string) $third->outcome)
        ->and($third->steps()->where('key', 'prune_images')->value('detail'))->toBe('removed 1')
        ->and($calls)->toContain("image rm sandbox-app:{$first->shortSha()}")
        ->not->toContain("image rm sandbox-app:{$second->shortSha()}")
        ->not->toContain("image rm sandbox-app:{$third->release->shortSha()}");
})->group('ssh-integration');

it('dumps the database on a real server, and says what to change when row-level security refuses', function () {
    $target = sandboxDockerTarget($this->slug);
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes(
        "DB_CONNECTION=pgsql\nDB_HOST=postgres\nDB_DATABASE=shop\nDB_USERNAME=shop\nDB_PASSWORD=s3cret\nDB_OWNER=rls_app\n"
    )]);

    $good = sandboxDockerRun($target, options: ['backup' => true]);
    $dumps = sandboxShell('ls -1 "$DIR"/.deploy/backups', ['DIR' => "/opt/srv/{$this->slug}"]);

    // Backing up as a role the policies apply to: pg_dump gives up, and the
    // deploy stops before the migrations with a way out.
    $target->project->update(['backup_role' => 'DB_OWNER']);
    $refused = sandboxDockerRun($target, options: ['backup' => true]);

    expect($good->status)->toBe(DeploymentStatus::Succeeded, (string) $good->outcome)
        ->and($good->release->backup)->toStartWith('shop-')
        ->and($dumps)->toContain($good->release->backup)
        ->and($refused->status)->toBe(DeploymentStatus::Failed)
        ->and($refused->outcome)->toContain('row-level security policies apply to that role')
        ->and($refused->steps()->where('key', 'backup')->value('exit_code'))->toBe(41)
        ->and($refused->logLines()->pluck('line')->implode("\n"))->toContain('query would be affected by row-level security policy');
})->group('ssh-integration');

it('stops on a real server when the stack’s network is missing, or Compose can’t read it', function () {
    $target = sandboxDockerTarget($this->slug, project: [
        'detection' => ['compose' => [['file' => 'compose.yaml', 'external_networks' => ['proxy-network']]]],
    ]);

    // proxy-network is on the server; the stack reads, so the deploy goes through.
    $good = sandboxDockerRun($target);

    $target->project->update(['detection' => ['compose' => [['file' => 'compose.yaml', 'external_networks' => ['nowhere-network']]]]]);
    $missing = sandboxDockerRun($target);

    $target->project->update(['detection' => ['compose' => [['file' => 'compose.yaml']]]]);
    commitFile($target->project->local_path, '.sandbox-broken-compose', 'yes');
    $broken = sandboxDockerRun($target);

    expect($good->status)->toBe(DeploymentStatus::Succeeded, (string) $good->outcome)
        ->and($good->steps()->where('key', 'stack')->value('detail'))->toBe('1 service')
        ->and($missing->status)->toBe(DeploymentStatus::Failed)
        ->and($missing->outcome)->toContain("external network nowhere-network, which isn't on this server")
        ->and($broken->status)->toBe(DeploymentStatus::Failed)
        ->and($broken->outcome)->toContain('services.app.volumes.0: invalid spec')
        ->and($broken->steps()->pluck('status', 'key')->map->value->all())
        ->toMatchArray(['stack' => 'failed', 'build' => 'skipped', 'migrate' => 'skipped', 'activate' => 'skipped']);
})->group('ssh-integration');

it('runs the project’s own health command on a real server, and fails the gate on a non-zero exit', function () {
    $target = sandboxDockerTarget($this->slug, project: ['health_command' => 'test -f public/index.php']);
    $good = sandboxDockerRun($target);

    $target->project->update(['health_command' => 'test -f public/nothing-here.php']);
    commitFile($target->project->local_path, 'public/index.php', '<?php echo "v2";');
    $failed = sandboxDockerRun($target);

    expect($good->status)->toBe(DeploymentStatus::Succeeded, (string) $good->outcome)
        ->and($good->steps()->where('key', 'health')->value('detail'))->toEndWith('health command ok')
        ->and($failed->status)->toBe(DeploymentStatus::RolledBack)
        ->and($failed->outcome)->toStartWith('The project’s health command failed with exit code 1.')
        ->and(trim(sandboxShell('readlink "$DIR/current"', ['DIR' => "/opt/srv/{$this->slug}"])))->toBe("releases/{$good->release->name}");
})->group('ssh-integration');

it('rolls an in-place project back onto an earlier release’s images, on a real server', function () {
    $target = sandboxDockerTarget(
        $this->slug,
        ['compose.yaml' => "services:\n  app:\n    build: .\n    image: sandbox-app:\${IMAGE_TAG}\n"],
        [
            'versionable' => false,
            'detection' => ['compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'sandbox-app']]]]],
        ],
    );

    $first = sandboxDockerRun($target)->release;
    commitFile($target->project->local_path, 'public/index.php', '<?php echo "v2";');
    $second = sandboxDockerRun($target);
    $pinsAfterDeploy = sandboxShell('cat "$DIR/.deployme-pins.env"', ['DIR' => "/opt/srv/{$this->slug}"]);

    $rollback = sandboxDockerRun($target, DeploymentKind::Rollback, ['release_id' => $first->id]);

    expect($second->status)->toBe(DeploymentStatus::Succeeded, (string) $second->outcome)
        ->and($pinsAfterDeploy)->toBe("IMAGE_TAG={$second->release->shortSha()}\n")
        ->and($rollback->status)->toBe(DeploymentStatus::Succeeded, (string) $rollback->outcome)
        // The folder now runs the first release's images again.
        ->and(sandboxShell('cat "$DIR/.deployme-pins.env"', ['DIR' => "/opt/srv/{$this->slug}"]))->toBe("IMAGE_TAG={$first->shortSha()}\n")
        ->and($target->refresh()->current_release_id)->toBe($first->id);
})->group('ssh-integration');
