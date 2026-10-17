<?php

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;
use App\Models\ProjectEnvFile;
use App\Models\Target;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config([
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 0,
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-docker-'.bin2hex(random_bytes(4)),
    ]);

    $this->ssh = fakeDeployScripts(new FakeSshClient);
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
    Process::fake(['*rsync*' => Process::result("Number of files: 4\nNumber of regular files transferred: 4\nTotal file size: 900 bytes\nTotal transferred file size: 900 bytes\n")]);
});

/**
 * A Docker project in a local git repository: stack "demo", app service
 * "app", migrating with it, on the fake server "box" at /opt/srv/demo.
 */
function dockerTarget(array $project = []): Target
{
    $repo = tempGitRepo(['compose.yaml' => "services:\n  app:\n    build: .\n", 'Dockerfile' => "FROM php:8.5-cli\n", 'deploy.sh' => "#!/bin/sh\n"]);

    return demoTarget([
        'source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null,
        'dockerized' => true, 'stack_name' => 'demo', 'compose_files' => ['compose.yaml'],
        'app_services' => ['app'], 'migrate_service' => 'app', 'shared_paths' => [],
        'delivery_strategies' => [['key' => 'push_archive', 'enabled' => true]],
        ...$project,
    ]);
}

function dockerRun(Target $target, array $options = [], DeploymentKind $kind = DeploymentKind::Deploy): Deployment
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => $kind, 'options' => $options]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

it('tags the images it builds after the commit, so a rollback gets its own images back', function () {
    $target = dockerTarget(['detection' => ['compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'demo-app']]]]]]);

    $deployment = dockerRun($target);
    $release = $deployment->release;
    $sha7 = (string) $release->shortSha();
    $writes = collect($this->ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-write-file')
        ->map(fn (array $call) => [scriptVar($call['script'], 'FILE'), scriptVar($call['script'], 'CONTENT')])->values();
    $order = collect($this->ssh->calls)->map(fn (array $call) => $call['script']->name.(($action = scriptVar($call['script'], 'ACTION')) ? ":{$action}" : ''))->values();

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and($release->image_pins)->toBe(['IMAGE_TAG' => $sha7])
        ->and($deployment->steps()->where('key', 'pins')->value('detail'))->toBe("IMAGE_TAG={$sha7}")
        ->and($writes->all())->toBe([["{$release->path}/.deployme-pins.env", "IMAGE_TAG={$sha7}\n"]])
        ->and($order->search('deploy-write-file'))->toBeLessThan($order->search('deploy-compose:build'));
});

function dockerSteps(Deployment $deployment): array
{
    return $deployment->steps()->get()->mapWithKeys(fn ($step) => [$step->key => $step->status->value])->all();
}

it('builds, backs up, migrates and recreates a Docker project from its new release', function () {
    $target = dockerTarget();
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes(
        "DB_CONNECTION=pgsql\nDB_HOST=postgres\nDB_DATABASE=demo\nDB_USERNAME=demo\nDB_PASSWORD=s3cret-pass\n"
    )]);
    fakeDeployScripts($this->ssh, ['deploy-compose' => fn ($alias, $script) => scriptVar($script, 'ACTION') === 'run'
        ? FakeSshClient::ok("   INFO  Running migrations.\n  2026_09_11_000000_create_orders_table .......... 12ms DONE\n  2026_09_11_000001_add_order_totals ............. 3ms DONE\n")
        : FakeSshClient::ok("Container app  Started\n")]);

    $deployment = dockerRun($target, ['backup' => true]);
    $release = $deployment->release;

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and(dockerSteps($deployment))->toMatchArray([
            'stack' => 'succeeded', 'build' => 'succeeded', 'backup' => 'succeeded', 'migrate' => 'succeeded',
            'activate' => 'succeeded', 'recreate' => 'succeeded', 'health' => 'succeeded',
        ])
        ->and($release->migrations)->toBe(2)
        ->and($release->ran_migrations)->toBeTrue()
        ->and($release->backup)->toBe('demo-20260911-120000.dump')
        ->and($deployment->steps()->where('key', 'migrate')->value('detail'))->toBe('2 migrations ran')
        ->and($deployment->steps()->where('key', 'recreate')->value('detail'))->toBe('app')
        ->and($deployment->steps()->where('key', 'health')->value('detail'))->toBe('services up after 4 s');

    // Every compose call runs as the project's stack, from the new release.
    $compose = collect($this->ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-compose')->pluck('script')->values();
    expect($compose->map(fn (RemoteScript $script) => scriptVar($script, 'ACTION'))->all())->toBe(['config', 'build', 'run', 'recreate'])
        ->and($compose->every(fn (RemoteScript $script) => scriptVar($script, 'DIR') === $release->path && scriptVar($script, 'STACK') === 'demo'))->toBeTrue()
        ->and(scriptVar($compose[2], 'COMMAND'))->toBe('php artisan migrate --force')
        ->and(scriptVar($compose[2], 'SERVICES'))->toBe('app');

    // The password reaches the server inside the script, and never the log.
    $backup = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-db-backup')['script'];
    expect(scriptVar($backup, 'CONTAINER'))->toBe('postgres')
        ->and(scriptVar($backup, 'DB_PASSWORD'))->toBe('s3cret-pass')
        ->and($deployment->logLines()->pluck('line')->implode("\n"))->not->toContain('s3cret-pass');
});

it('skips the backup unless asked, and the migrations when told to', function () {
    $target = dockerTarget();

    expect(dockerSteps(dockerRun($target)))->toMatchArray(['backup' => 'skipped', 'migrate' => 'succeeded'])
        ->and(dockerSteps(dockerRun($target, ['backup' => true, 'skip_migrations' => true])))->toMatchArray(['backup' => 'skipped', 'migrate' => 'skipped']);
});

it('waits as long as the slowest service takes to stop when it recreates them', function () {
    // An ingest worker finishing a 30-minute import before compose replaces it.
    $target = dockerTarget(['detection' => ['compose' => [['file' => 'compose.yaml', 'stop_grace' => ['app' => 1900, 'mail' => 120]]]]]);

    $deployment = dockerRun($target);
    $timeouts = collect($this->ssh->calls)
        ->filter(fn (array $call) => $call['script']->name === 'deploy-compose')
        ->mapWithKeys(fn (array $call) => [scriptVar($call['script'], 'ACTION') => $call['timeout']]);

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and($timeouts->all())->toMatchArray(['recreate' => 2200, 'run' => 1800]);
});

it('switches back and recreates the old release’s services when the new one stays unhealthy', function () {
    $target = dockerTarget();
    $first = dockerRun($target)->release;
    fakeDeployScripts($this->ssh, ['deploy-compose-health' => fn ($alias, $script) => scriptVar($script, 'DIR') === $first->path
        ? FakeSshClient::ok("✓ Everything is up after 2 s\n::deployme::after=2\n")
        : new SshResult(30, "✗ After 180 s: app (running, unhealthy)\n::deployme::waiting=app (running, unhealthy)\n", '', 180.0)]);

    $failed = dockerRun($target);

    expect($failed->status)->toBe(DeploymentStatus::RolledBack)
        ->and($failed->outcome)->toStartWith('Health check failed after 180 s: app (running, unhealthy). Switched back to release '.$first->name)
        ->and($failed->steps()->where('key', 'rollback')->value('detail'))->toBe("current → releases/{$first->name} · app services recreated · services up after 2 s")
        ->and($target->refresh()->current_release_id)->toBe($first->id);

    $recreated = collect($this->ssh->calls)
        ->filter(fn (array $call) => $call['script']->name === 'deploy-compose' && scriptVar($call['script'], 'ACTION') === 'recreate')
        ->map(fn (array $call) => scriptVar($call['script'], 'DIR'))
        ->values();
    expect($recreated->slice(-2)->values()->all())->toBe([$failed->release->path, $first->path]);
});

it('leaves the stack to the project’s own deploy script', function () {
    $target = dockerTarget(['versionable' => false, 'activation' => 'script', 'activation_script' => 'deploy.sh']);

    $deployment = dockerRun($target);

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and(dockerSteps($deployment))->toMatchArray(['build' => 'skipped', 'migrate' => 'skipped', 'activate' => 'succeeded', 'recreate' => 'skipped', 'health' => 'skipped'])
        ->and(collect($this->ssh->calls)->pluck('script.name')->all())->not->toContain('deploy-compose');
});

it('runs service hooks in a one-off container of their service', function () {
    $target = dockerTarget(['migrate_service' => null]);
    $target->project->hooks()->create(['phase' => 'release', 'name' => 'Cache config', 'command' => 'php artisan config:cache', 'run_in' => 'service_run', 'service' => 'app', 'timeout' => 120, 'position' => 1]);

    $deployment = dockerRun($target);
    $hook = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-compose' && scriptVar($call['script'], 'ACTION') === 'run')['script'];

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and(scriptVar($hook, 'COMMAND'))->toBe('php artisan config:cache')
        ->and(scriptVar($hook, 'SERVICES'))->toBe('app');
});

it('adopts what an earlier deployment left in an in-place folder, with its image pins', function () {
    $target = demoTarget([
        'versionable' => false, 'dockerized' => true, 'stack_name' => 'example', 'compose_files' => ['compose.yaml'],
        'detection' => ['compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/o/app']]]]],
    ]);
    $sha = str_repeat('c', 40);
    fakeDeployScripts($this->ssh, ['deploy-inspect' => FakeSshClient::ok(
        "✓ A git checkout at ccccccc on main\n::deployme::commit={$sha}\n::deployme::subject=Blog: tags page\n::deployme::branch=main\n"
        ."✓ IMAGE_TAG=9e1f2aa in .env\n::deployme::pins=IMAGE_TAG=9e1f2aa|\n✓ Stack example is running(3)\n::deployme::stack=running(3)\n::deployme::files=7\n"
    )]);

    $deployment = dockerRun($target, kind: DeploymentKind::Adopt);
    $release = $deployment->release;
    $inspect = collect($this->ssh->calls)->last(fn (array $call) => $call['script']->name === 'deploy-inspect')['script'];

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and($deployment->outcome)->toBe('ccccccc in /opt/srv/demo is the live release now. The next deploy replaces it in place.')
        ->and($deployment->steps()->where('key', 'inspect')->value('detail'))->toBe('ccccccc on main · IMAGE_TAG=9e1f2aa · stack example running(3)')
        ->and(scriptVar($inspect, 'VARS'))->toBe('IMAGE_TAG')
        ->and($release->adopted)->toBeTrue()
        ->and($release->commit_message)->toBe('Blog: tags page')
        // The tags it was running are what a rollback to it would put back.
        ->and($release->image_pins)->toBe(['IMAGE_TAG' => '9e1f2aa'])
        ->and($target->refresh()->current_release_id)->toBe($release->id)
        ->and($target->last_deployed_at)->toBeNull();
});

it('takes the commit from .deploy-state when the folder is no git checkout', function () {
    $target = demoTarget(['versionable' => false, 'dockerized' => true, 'stack_name' => 'example', 'compose_files' => ['compose.yaml']]);
    [$current, $before] = [str_repeat('a', 40), str_repeat('b', 40)];
    fakeDeployScripts($this->ssh, ['deploy-inspect' => FakeSshClient::ok(
        "✓ .deploy-state says CURRENT_SHA=aaaaaaa\n::deployme::state_current={$current}\n"
        ."· .deploy-state says the deploy before it was bbbbbbb\n::deployme::state_previous={$before}\n::deployme::files=7\n"
    )]);

    $deployment = dockerRun($target, kind: DeploymentKind::Adopt);

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and($deployment->release->commit_sha)->toBe($current)
        ->and($deployment->steps()->where('key', 'inspect')->value('detail'))->toBe('aaaaaaa from .deploy-state')
        ->and($deployment->logLines()->pluck('line')->implode("\n"))->toContain('The deployment before this one was bbbbbbb');
});

it('won’t adopt into a project that deploys releases', function () {
    $deployment = dockerRun(demoTarget(), kind: DeploymentKind::Adopt);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toContain('Switch Versionable off');
});

it('removes the images of the releases it pruned, and keeps the ones still pinned', function () {
    $target = dockerTarget([
        'keep_releases' => 2,
        'detection' => ['compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'demo-app']]]]],
    ]);

    $first = dockerRun($target)->release;
    commitFile($target->project->local_path, 'index.php', '<?php echo 2;');
    $second = dockerRun($target)->release;
    commitFile($target->project->local_path, 'index.php', '<?php echo 3;');
    $third = dockerRun($target);

    $prune = collect($this->ssh->calls)->last(fn (array $call) => $call['script']->name === 'deploy-prune-images')['script'];

    expect($third->status)->toBe(DeploymentStatus::Succeeded, (string) $third->outcome)
        ->and($first->refresh()->pruned_at)->not->toBeNull()
        ->and($second->refresh()->pruned_at)->toBeNull()
        // The pruned release's tag goes; the live one's and the one a rollback
        // would need stay.
        ->and(scriptVar($prune, 'TAGS'))->toBe((string) $first->shortSha())
        ->and(scriptVar($prune, 'KEEP_TAGS'))->toBe($third->release->shortSha()."\n".$second->shortSha())
        ->and(scriptVar($prune, 'DIR'))->toBe($third->release->path)
        ->and($third->steps()->where('key', 'prune_images')->value('detail'))->toBe('removed 1');
});

it('leaves the images alone while every release that pinned one is still there', function () {
    $target = dockerTarget(['detection' => ['compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'demo-app']]]]]]);

    dockerRun($target);
    commitFile($target->project->local_path, 'index.php', '<?php echo 2;');
    $second = dockerRun($target);

    expect($second->status)->toBe(DeploymentStatus::Succeeded, (string) $second->outcome)
        ->and($second->steps()->where('key', 'prune_images')->value('detail'))->toBe('nothing to remove · 2 pinned')
        ->and(collect($this->ssh->calls)->pluck('script.name')->all())->not->toContain('deploy-prune-images');
});

/**
 * A Docker project whose env file points at a Postgres database.
 */
function backupTarget(array $project = []): Target
{
    $target = dockerTarget($project);
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes(
        "DB_CONNECTION=pgsql\nDB_HOST=postgres\nDB_DATABASE=demo\nDB_USERNAME=demo_app\nDB_PASSWORD=s3cret-pass\nDB_SUPERUSER=demo_owner\nDB_SUPERUSER_PASSWORD=owner-pass\n"
    )]);

    return $target;
}

it('backs up as the role the project names, and keeps as many dumps as it says', function () {
    $target = backupTarget(['backup_role' => 'postgres', 'backup_keep' => 12]);

    dockerRun($target, ['backup' => true]);
    $target->project->update(['backup_role' => 'DB_SUPERUSER', 'backup_keep' => 5]);
    $deployment = dockerRun($target, ['backup' => true]);

    $backups = collect($this->ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-db-backup')->pluck('script')->values();
    [$asRole, $asKey] = [$backups->first(), $backups->last()];

    // A role written out connects over the container's own socket: no password.
    expect(scriptVar($asRole, 'DB_USER'))->toBe('postgres')
        ->and(scriptVar($asRole, 'DB_PASSWORD'))->toBe('')
        ->and(scriptVar($asRole, 'KEEP'))->toBe('12')
        // An env key brings the password beside it.
        ->and(scriptVar($asKey, 'DB_USER'))->toBe('demo_owner')
        ->and(scriptVar($asKey, 'DB_PASSWORD'))->toBe('owner-pass')
        ->and(scriptVar($asKey, 'KEEP'))->toBe('5')
        ->and($deployment->steps()->where('key', 'backup')->value('detail'))->toEndWith('· 5 kept');
});

it('refuses a database name a dump can’t be named after, before anything reaches the server', function (string $name) {
    $target = dockerTarget();
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes(
        "DB_CONNECTION=pgsql\nDB_HOST=postgres\nDB_DATABASE={$name}\nDB_USERNAME=demo\nDB_PASSWORD=s3cret-pass\n"
    )]);

    $deployment = dockerRun($target, ['backup' => true]);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toStartWith("DB_DATABASE in the project’s env file, “{$name}”, isn’t a database name deploy.me backs up")
        ->and(dockerSteps($deployment))->toMatchArray(['backup' => 'failed', 'migrate' => 'skipped', 'activate' => 'skipped'])
        ->and(collect($this->ssh->calls)->map(fn (array $call) => $call['script']->name))->not->toContain('deploy-db-backup');
})->with([
    'a path' => ['../../../tmp/x'],
    'hidden' => ['.demo'],
    'a glob' => ['demo*'],
]);

it('says what to change when row-level security refuses the dump', function () {
    $target = backupTarget();
    fakeDeployScripts($this->ssh, ['deploy-db-backup' => new SshResult(41,
        "✗ pg_dump can't read demo as demo_app: row-level security policies apply to that role\n", '', 1.0)]);

    $deployment = dockerRun($target, ['backup' => true]);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toContain('row-level security policies apply to that role')
        ->and($deployment->outcome)->toContain('“Back up as” on the project’s Settings tab')
        ->and($deployment->outcome)->toContain('the postgres container’s superuser')
        ->and(dockerSteps($deployment))->toMatchArray(['backup' => 'failed', 'migrate' => 'skipped', 'activate' => 'skipped']);
});

it('stops a deploy while another Compose project runs from the folder', function () {
    $target = dockerTarget();
    fakeDeployScripts($this->ssh, ['deploy-preflight' => FakeSshClient::ok(
        "✓ /opt/srv is writable for deploy\n::deployme::user=deploy\n::deployme::free=104857600000\n::deployme::gnu_mv=1\n"
        ."::deployme::compose=2.39.1\n! Compose project example already runs from /opt/srv/demo, not demo\n"
        ."::deployme::drift=example\n::deployme::ok=1\n"
    )]);

    $deployment = dockerRun($target);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toContain('Compose project example already runs from /opt/srv/demo, but this project’s stack is demo')
        ->and($deployment->outcome)->toContain('Set the stack name to example in the project’s settings')
        ->and($deployment->steps()->where('key', 'preflight')->value('exit_code'))->toBe(18)
        ->and(dockerSteps($deployment))->toMatchArray(['preflight' => 'failed', 'lease' => 'skipped', 'upload' => 'skipped']);

    // Preparing the folder starts nothing, so it only says so.
    $bootstrap = dockerRun($target, kind: DeploymentKind::Bootstrap);

    expect($bootstrap->status)->toBe(DeploymentStatus::Succeeded, (string) $bootstrap->outcome)
        ->and($bootstrap->logLines()->pluck('line')->implode("\n"))->toContain('A deploy will stop until the stack name matches it');
});

it('checks the networks the stack joins, and that Compose can read it, before it builds anything', function () {
    $target = dockerTarget(['detection' => ['compose' => [['file' => 'compose.yaml', 'external_networks' => ['proxy-network']]]]]);

    fakeDeployScripts($this->ssh, ['deploy-preflight' => new SshResult(19,
        "✗ The stack joins the external network proxy-network, which isn't on this server. Create it (docker network create proxy-network), or take it out of the compose file.\n", '', 1.0)]);
    $missing = dockerRun($target);
    $preflight = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-preflight')['script'];

    fakeDeployScripts($this->ssh, ['deploy-compose' => fn ($alias, RemoteScript $script) => scriptVar($script, 'ACTION') === 'config'
        ? new SshResult(6, "✗ Compose can't read the stack from /opt/srv/demo: services.app.volumes.0: invalid spec\n", '', 1.0)
        : FakeSshClient::ok("Container app  Started\n")]);
    $broken = dockerRun($target);

    expect(scriptVar($preflight, 'NETWORKS'))->toBe('proxy-network')
        ->and($missing->status)->toBe(DeploymentStatus::Failed)
        ->and($missing->outcome)->toContain("external network proxy-network, which isn't on this server")
        ->and(dockerSteps($missing))->toMatchArray(['preflight' => 'failed', 'upload' => 'skipped'])
        // Compose can't read the stack: nothing is built, migrated or replaced.
        ->and($broken->status)->toBe(DeploymentStatus::Failed)
        ->and($broken->outcome)->toContain('services.app.volumes.0: invalid spec')
        ->and(dockerSteps($broken))->toMatchArray([
            'upload' => 'succeeded', 'stack' => 'failed', 'build' => 'skipped', 'migrate' => 'skipped', 'activate' => 'skipped',
        ]);
});

it('gives the project’s own health command the last word on a release', function () {
    $target = dockerTarget(['health_command' => 'php artisan app:smoke-test', 'auto_rollback' => true]);
    $first = dockerRun($target)->release;

    $hook = collect($this->ssh->calls)->last(fn (array $call) => $call['script']->name === 'deploy-hook')['script'];

    // Everything Docker says is fine, and the project's own check still fails.
    fakeDeployScripts($this->ssh, ['deploy-hook' => new SshResult(3, "queue is 40 000 jobs behind\n", '', 1.0)]);
    $failed = dockerRun($target);

    expect(scriptVar($hook, 'COMMAND'))->toBe('php artisan app:smoke-test')
        ->and(scriptVar($hook, 'DIR'))->toBe($first->path)
        ->and(scriptVar($hook, 'RELEASE'))->toBe($first->name)
        ->and($target->refresh()->current_release_id)->toBe($first->id)
        ->and($failed->status)->toBe(DeploymentStatus::RolledBack)
        ->and($failed->outcome)->toStartWith('The project’s health command failed with exit code 3.')
        ->and($failed->steps()->where('key', 'health')->value('exit_code'))->toBe(3)
        ->and(dockerSteps($failed))->toMatchArray(['health' => 'failed', 'rollback' => 'succeeded']);
});

it('rolls a project that deploys in place back onto the images an earlier release ran', function () {
    $target = dockerTarget([
        'versionable' => false,
        'detection' => ['compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'demo-app']]]]],
    ]);

    $first = dockerRun($target)->release;
    commitFile($target->project->local_path, 'index.php', '<?php echo 2;');
    $second = dockerRun($target)->release;

    $rollback = dockerRun($target, ['release_id' => $first->id], DeploymentKind::Rollback);
    $write = collect($this->ssh->calls)->last(fn (array $call) => $call['script']->name === 'deploy-write-file')['script'];

    // No folder to point current at: the pins go back and the stack comes up on them.
    expect($rollback->status)->toBe(DeploymentStatus::Succeeded, (string) $rollback->outcome)
        ->and($rollback->outcome)->toBe("Release {$first->name} is live again on {$target->server->name}.")
        ->and(dockerSteps($rollback))->toMatchArray([
            'pick' => 'succeeded', 'restore_pins' => 'succeeded', 'activate' => 'skipped', 'recreate' => 'succeeded', 'health' => 'succeeded',
        ])
        ->and($rollback->steps()->where('key', 'restore_pins')->value('detail'))->toBe("IMAGE_TAG={$first->shortSha()}")
        ->and(scriptVar($write, 'FILE'))->toBe('/opt/srv/demo/.deployme-pins.env')
        ->and(scriptVar($write, 'CONTENT'))->toBe("IMAGE_TAG={$first->shortSha()}\n")
        ->and($first->shortSha())->not->toBe($second->shortSha())
        ->and($target->refresh()->current_release_id)->toBe($first->id);
});

it('refuses to roll back to an in-place release that recorded no images', function () {
    $target = dockerTarget(['versionable' => false]);
    $first = dockerRun($target)->release;
    commitFile($target->project->local_path, 'index.php', '<?php echo 2;');
    dockerRun($target);

    $rollback = dockerRun($target, ['release_id' => $first->id], DeploymentKind::Rollback);

    expect($rollback->status)->toBe(DeploymentStatus::Failed)
        ->and($rollback->outcome)->toContain('didn’t record which images it ran')
        ->and($first->refresh()->canPinBack())->toBeFalse();
});

it('puts the previous images back when a deploy in place turns out unhealthy', function () {
    $target = dockerTarget([
        'versionable' => false,
        'detection' => ['compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'demo-app']]]]],
    ]);
    $first = dockerRun($target)->release;

    commitFile($target->project->local_path, 'index.php', '<?php echo 2;');
    fakeDeployScripts($this->ssh, ['deploy-compose-health' => new SshResult(30,
        "✗ After 180 s: app (running, unhealthy)\n::deployme::waiting=app (running, unhealthy)\n", '', 180.0)]);
    $failed = dockerRun($target);

    expect($failed->status)->toBe(DeploymentStatus::RolledBack)
        ->and($failed->outcome)->toEndWith("Switched back to release {$first->name}, which is live again.")
        ->and($failed->steps()->where('key', 'rollback')->value('detail'))->toStartWith("images → IMAGE_TAG={$first->shortSha()}")
        ->and($target->refresh()->current_release_id)->toBe($first->id);
});
