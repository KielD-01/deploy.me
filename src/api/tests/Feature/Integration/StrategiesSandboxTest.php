<?php

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Target;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

// Server git and GHCR deploys against the local sandbox (`make sandbox`):
// a bare repository on the sandbox stands in for GitHub, and its docker shim
// for the registry. Skipped when the sandbox isn't running.
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
    $this->slug = 'it-strategy-'.bin2hex(random_bytes(3));
    onBox(': > /tmp/docker-calls.log');
});

afterEach(function () {
    if (isset($this->slug)) {
        onBox('rm -rf -- "$DIR" "$DIR-origin.git"', ['DIR' => "/opt/srv/{$this->slug}"]);
    }
});

function onBox(string $script, array $vars = []): string
{
    return app(SshClient::class)->run('sandbox', RemoteScript::inline('shell', $script, $vars))->throw()->stdout;
}

function boxTarget(string $slug, array $project, array $files = ['index.html' => 'from the Mac']): Target
{
    $server = Server::factory()->create(['ssh_alias' => 'sandbox', 'default_deploy_path' => '/opt/srv']);
    $project = Project::factory()->create([
        'slug' => $slug, 'name' => $slug, 'source' => 'local', 'local_path' => tempGitRepo($files), 'shared_paths' => [],
        ...$project,
    ]);

    return Target::factory()->create(['server_id' => $server->id, 'project_id' => $project->id, 'base_dir' => '/opt/srv']);
}

function boxRun(Target $target): Deployment
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

it('lets a real server fetch the commit from its own remote', function () {
    $origin = "/opt/srv/{$this->slug}-origin.git";
    onBox(<<<'SH'
        git init -q --bare "$ORIGIN"
        work=$(mktemp -d) && cd "$work" && git init -q
        printf 'from the server remote' > index.html
        git add index.html && git -c user.name=Test -c user.email=test@example.com commit -qm "Fetched by the server"
        git push -q "$ORIGIN" HEAD:refs/heads/main && rm -rf "$work"
        SH, ['ORIGIN' => $origin]);
    $sha = trim(onBox('git --git-dir="$ORIGIN" rev-parse main', ['ORIGIN' => $origin]));

    $target = boxTarget($this->slug, [
        'github_owner' => null, 'github_repo' => null, 'git_remote' => $origin,
        'delivery_strategies' => [['key' => 'server_git', 'enabled' => true]],
    ]);

    $deployment = boxRun($target);

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and($deployment->release->commit_sha)->toBe($sha)
        ->and($deployment->commit_message)->toBe('Fetched by the server')
        ->and(onBox('cat "$DIR/current/index.html"; [ -d "$DIR/.deploy/repo.git" ] && printf " mirrored"', ['DIR' => "/opt/srv/{$this->slug}"]))
        ->toBe('from the server remote mirrored');
})->group('ssh-integration');

it('logs in to the registry for the pull only, on a real server', function () {
    // The sandbox's docker shim reports one service, "app".
    $repo = tempGitRepo(['compose.yaml' => "services:\n  app:\n    image: ghcr.io/kield-01/demo-api:\${API_TAG}\n"]);
    $sha = trim(Process::path($repo)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
    $sha7 = substr($sha, 0, 7);
    Credential::factory()->create();
    Http::fake([
        'api.github.com/repos/KielD-01/demo/commits*' => Http::response([['sha' => $sha, 'commit' => ['message' => 'Images', 'committer' => ['date' => now()->toIso8601String()]]]]),
        'api.github.com/repos/KielD-01/demo/actions/runs*' => Http::response(['workflow_runs' => [[
            'id' => 5, 'name' => 'CI/CD', 'path' => '.github/workflows/ci-cd.yml', 'run_number' => 5,
            'status' => 'completed', 'conclusion' => 'success', 'head_sha' => $sha, 'head_commit' => ['message' => 'Images'],
        ]]]),
        'ghcr.io/token*' => Http::response(['token' => 'registry-token']),
        'ghcr.io/v2/*' => Http::response('', 200),
    ]);

    $target = boxTarget($this->slug, [
        'github_owner' => 'KielD-01', 'github_repo' => 'demo', 'local_path' => $repo,
        'dockerized' => true, 'stack_name' => str_replace('-', '_', $this->slug), 'compose_files' => ['compose.yaml'],
        'app_services' => ['app'], 'migrate_service' => null, 'health_timeout' => 10,
        'delivery_strategies' => [['key' => 'ghcr_image', 'enabled' => true]],
        'detection' => [
            'workflows' => [['file' => 'ci-cd.yml', 'images' => [['image' => 'ghcr.io/{owner}/{repo}-{service}', 'tag' => 'sha-{sha7}', 'count' => 1]]]],
            'compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'API_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/kield-01/demo-api']]]],
        ],
    ]);

    $deployment = boxRun($target);
    $root = "/opt/srv/{$this->slug}";

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and(onBox('cat /tmp/docker-calls.log'))
        ->toContain('login ghcr.io -u KielD-01 --password-stdin')
        ->toContain("--project-directory {$deployment->release->path} -f compose.yaml --env-file {$deployment->release->path}/.deployme-pins.env pull app")
        // The pins are the release's own file, and the login is gone after the pull.
        ->and(onBox('f="$DIR/current/.deployme-pins.env"; [ -L "$f" ] && echo link || echo file; cat "$f"; [ -e "$DIR/.deploy/docker-$RUN" ] && echo "login left" || echo "login gone"', ['DIR' => $root, 'RUN' => (string) $deployment->id]))
        ->toBe("file\nAPI_TAG=sha-{$sha7}\nlogin gone\n");
})->group('ssh-integration');
