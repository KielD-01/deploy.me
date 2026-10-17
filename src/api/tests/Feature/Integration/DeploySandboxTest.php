<?php

use App\Actions\Deployments\CancelDeployment;
use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseStatus;
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
use Illuminate\Support\Facades\Process;

// Real deploys of a throwaway git repository to the local sandbox (`make
// sandbox`), each in its own folder under /opt/srv that's removed afterwards.
// Health checks ask this app's /up from the sandbox. Skipped when the sandbox
// isn't running.
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
    $this->slug = 'it-deploy-'.bin2hex(random_bytes(3));
});

afterEach(function () {
    if (isset($this->slug)) {
        app(SshClient::class)->run('sandbox', RemoteScript::inline('cleanup', 'rm -rf -- "$DIR"', ['DIR' => "/opt/srv/{$this->slug}"]));
    }
});

function sandboxProjectTarget(string $slug, array $project = []): Target
{
    $repo = tempGitRepo([
        'public/index.html' => '<h1>v1</h1>',
        'storage/logs/.gitignore' => "*\n!.gitignore\n",
        'README.md' => 'The same in every release',
    ]);

    $server = Server::factory()->create(['ssh_alias' => 'sandbox', 'default_deploy_path' => '/opt/srv']);
    $project = Project::factory()->create([
        'slug' => $slug, 'name' => $slug,
        'source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null,
        'shared_paths' => ['storage'],
        'delivery_strategies' => [['key' => 'push_archive', 'enabled' => true]],
        ...$project,
    ]);

    return Target::factory()->create(['server_id' => $server->id, 'project_id' => $project->id, 'base_dir' => '/opt/srv']);
}

function sandboxRun(Target $target, DeploymentKind $kind = DeploymentKind::Deploy, array $options = []): Deployment
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => $kind, 'options' => $options]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

function onSandbox(string $script, array $vars = []): string
{
    return app(SshClient::class)->run('sandbox', RemoteScript::inline('check', $script, $vars))->throw()->stdout;
}

function commitToRepo(string $repo, string $path, string $contents, string $message): void
{
    file_put_contents("{$repo}/{$path}", $contents);
    $git = fn (string ...$args) => Process::path($repo)->env([
        'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com',
        'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
    ])->run(['git', ...$args])->throw();

    $git('add', '--all');
    $git('commit', '--quiet', '-m', $message);
}

it('deploys releases, shares files, rolls back and prunes on a real server', function () {
    $target = sandboxProjectTarget($this->slug, ['keep_releases' => 2, 'health_url' => 'http://deployme-web:8000/up', 'health_timeout' => 30]);
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("APP_NAME=Sandbox\n")]);
    $root = "/opt/srv/{$this->slug}";

    $first = sandboxRun($target);
    expect($first->status)->toBe(DeploymentStatus::Succeeded, (string) $first->outcome);
    $one = $first->release;

    $layout = onSandbox(<<<'SH'
        cd -- "$DIR"
        readlink current; echo
        cat current/public/index.html; echo
        readlink current/storage; echo
        readlink current/.env; echo
        cat current/.env
        [ -d shared/storage/logs ] && echo "shared logs"
        grep -o '"release": "[^"]*"' .deploy/state.json
        SH, ['DIR' => $root]);

    expect($layout)->toContain("releases/{$one->name}")
        ->toContain('<h1>v1</h1>')
        ->toContain("{$root}/shared/storage")
        ->toContain("{$root}/shared/.env")
        ->toContain('APP_NAME=Sandbox')
        ->toContain('shared logs')
        ->toContain("\"release\": \"{$one->name}\"")
        ->and($first->steps()->where('key', 'health')->value('detail'))->toStartWith('HTTP 200');

    // The next release: unchanged files are hard links to the live one's.
    commitToRepo($target->project->local_path, 'public/index.html', '<h1>v2</h1>', 'Second version');
    $second = sandboxRun($target);
    $two = $second->release;
    expect($second->status)->toBe(DeploymentStatus::Succeeded, (string) $second->outcome);

    $linked = onSandbox('cd -- "$DIR/releases" && stat -c %i "$A/README.md" "$B/README.md" | uniq | wc -l; cat "$DIR/current/public/index.html"', ['DIR' => $root, 'A' => $one->name, 'B' => $two->name]);
    expect($linked)->toBe("1\n<h1>v2</h1>");

    // Back to the first one.
    $rollback = sandboxRun($target, DeploymentKind::Rollback, ['release_id' => $one->id]);
    expect($rollback->status)->toBe(DeploymentStatus::Succeeded, (string) $rollback->outcome)
        ->and(onSandbox('readlink "$DIR/current"', ['DIR' => $root]))->toContain("releases/{$one->name}");

    // A third deploy keeps two: itself and the one it replaced (the first).
    commitToRepo($target->project->local_path, 'public/index.html', '<h1>v3</h1>', 'Third version');
    $third = sandboxRun($target);
    expect($third->status)->toBe(DeploymentStatus::Succeeded, (string) $third->outcome)
        ->and(onSandbox('ls "$DIR/releases" | sort | tr "\n" " "', ['DIR' => $root]))->toBe(collect([$one->name, $third->release->name])->sort()->implode(' ').' ')
        ->and($two->refresh()->status)->toBe(ReleaseStatus::Pruned);
})->group('ssh-integration');

it('switches back on a real server when the health check fails', function () {
    $target = sandboxProjectTarget($this->slug, ['health_url' => 'http://deployme-web:8000/up', 'health_timeout' => 20]);
    $good = sandboxRun($target);
    expect($good->status)->toBe(DeploymentStatus::Succeeded, (string) $good->outcome);

    $target->project->update(['health_url' => 'http://127.0.0.1:9/up', 'health_timeout' => 10]);
    commitToRepo($target->project->local_path, 'public/index.html', '<h1>broken</h1>', 'Broken version');
    $bad = sandboxRun($target);

    expect($bad->status)->toBe(DeploymentStatus::RolledBack, (string) $bad->outcome)
        ->and($bad->outcome)->toContain("Switched back to release {$good->release->name}")
        ->and(onSandbox('readlink "$DIR/current"; cat "$DIR/current/public/index.html"', ['DIR' => "/opt/srv/{$this->slug}"]))
        ->toBe("releases/{$good->release->name}\n<h1>v1</h1>");
})->group('ssh-integration');

it('retries a real deploy from its failed health check once the app answers', function () {
    $target = sandboxProjectTarget($this->slug, ['health_url' => 'http://deployme-web:8000/up', 'health_timeout' => 20]);
    $good = sandboxRun($target);
    expect($good->status)->toBe(DeploymentStatus::Succeeded, (string) $good->outcome);

    $target->project->update(['health_url' => 'http://127.0.0.1:9/up', 'health_timeout' => 10]);
    commitToRepo($target->project->local_path, 'public/index.html', '<h1>v2</h1>', 'Second version');
    $bad = sandboxRun($target);
    expect($bad->status)->toBe(DeploymentStatus::RolledBack, (string) $bad->outcome);

    // The health check asks where the app answers now.
    $target->project->update(['health_url' => 'http://deployme-web:8000/up', 'health_timeout' => 20]);
    $retry = retryDeployment($bad);
    app(PipelineRunner::class)->run($retry);

    expect($retry->refresh()->status)->toBe(DeploymentStatus::Succeeded, (string) $retry->outcome)
        ->and($retry->resume_from)->toBe('health')
        ->and($retry->release_id)->toBe($bad->release_id)
        ->and($retry->steps()->where('key', 'upload')->value('detail'))->toBe("done in #{$bad->id}")
        ->and(onSandbox('readlink "$DIR/current"; cat "$DIR/current/public/index.html"', ['DIR' => "/opt/srv/{$this->slug}"]))
        ->toBe("releases/{$bad->release->name}\n<h1>v2</h1>");
})->group('ssh-integration');

it('stops the commands a cancelled deploy left running on a real server', function () {
    $target = sandboxProjectTarget($this->slug);
    $target->project->hooks()->create(['phase' => 'release', 'name' => 'Sleep', 'command' => 'echo sleeping; sleep 120', 'run_in' => 'host', 'timeout' => 300, 'position' => 1]);
    onSandbox('pkill -u deploy -x -f "sleep 120"; true');
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy]);

    // "Cancel" is clicked once the hook says it's sleeping.
    Event::fake([DeploymentUpdated::class]);
    Event::listen(DeploymentLogged::class, function (DeploymentLogged $event) use ($deployment) {
        if (collect($event->lines)->contains(fn (array $line) => $line[4] === 'sleeping')) {
            app(CancelDeployment::class)($deployment);
        }
    });

    $started = microtime(true);
    app(PipelineRunner::class)->run($deployment);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Cancelled, (string) $deployment->outcome)
        ->and(microtime(true) - $started)->toBeLessThan(60)
        ->and(onSandbox('pgrep -u deploy -x -f "sleep 120" || echo none'))->toBe("none\n")
        ->and(onSandbox('ls -A "$DIR/.deploy/runs"', ['DIR' => "/opt/srv/{$this->slug}"]))->toBe('');
})->group('ssh-integration');

it('puts an in-place release into place on a real server: what it dropped goes, the server’s own files stay', function () {
    $target = sandboxProjectTarget($this->slug, ['versionable' => false]);
    $repo = $target->project->local_path;
    $root = "/opt/srv/{$this->slug}";
    commitToRepo($repo, '.gitignore', "/vendor/\n", 'Ignore vendor');
    commitToRepo($repo, 'public/old.html', 'old', 'Add a page');

    expect(sandboxRun($target)->status)->toBe(DeploymentStatus::Succeeded);
    // What the server has of its own: an install of the ignored vendor/, a log in the shared storage/, the env file.
    onSandbox('mkdir -p "$DIR/vendor/pkg" "$DIR/storage/logs" && printf kept > "$DIR/vendor/pkg/installed.php" && printf keep > "$DIR/storage/logs/app.log" && printf secret > "$DIR/.env"', ['DIR' => $root]);

    // The next commit drops the page.
    unlink("{$repo}/public/old.html");
    commitToRepo($repo, 'public/index.html', '<h1>v2</h1>', 'Drop the old page');
    $second = sandboxRun($target->refresh());

    expect($second->status)->toBe(DeploymentStatus::Succeeded, (string) $second->outcome)
        ->and(onSandbox('cd "$DIR" && cat public/index.html vendor/pkg/installed.php storage/logs/app.log .env; [ -e public/old.html ] && printf " old"; ls -A .deploy/incoming | wc -l | tr -d " "', ['DIR' => $root]))
        ->toBe("<h1>v2</h1>keptkeepsecret0\n");
})->group('ssh-integration');

it('deploys in place on a real server and leaves its storage alone', function () {
    $target = sandboxProjectTarget($this->slug, ['versionable' => false]);
    onSandbox('mkdir -p "$DIR/storage/logs" && printf keep > "$DIR/storage/logs/app.log"', ['DIR' => "/opt/srv/{$this->slug}"]);

    $deployment = sandboxRun($target);

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and(onSandbox('cd "$DIR" && cat public/index.html storage/logs/app.log; [ -e current ] || printf " no current"', ['DIR' => "/opt/srv/{$this->slug}"]))
        ->toBe('<h1>v1</h1>keep no current');
})->group('ssh-integration');
